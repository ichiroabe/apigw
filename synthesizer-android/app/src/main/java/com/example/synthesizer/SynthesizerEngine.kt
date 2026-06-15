package com.example.synthesizer

import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioTrack
import kotlinx.coroutines.*
import kotlin.math.*

enum class WaveType { SINE, SQUARE, SAWTOOTH, TRIANGLE }

data class AdsrEnvelope(
    var attackMs: Float = 10f,
    var decayMs: Float = 100f,
    var sustainLevel: Float = 0.7f,
    var releaseMs: Float = 200f
)

class SynthesizerEngine {

    companion object {
        const val SAMPLE_RATE = 44100
        const val BUFFER_SIZE = 1024
    }

    var waveType = WaveType.SINE
    var adsr = AdsrEnvelope()
    var masterVolume = 0.8f
    var filterCutoff = 1.0f   // 0.0–1.0 normalized
    var filterResonance = 0.0f // 0.0–1.0 normalized
    var reverbMix = 0.0f
    var delayMix = 0.0f
    var delayTimeMs = 300f

    private val audioTrack: AudioTrack
    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    private val activeNotes = mutableMapOf<Int, NoteState>()
    private val lock = Any()

    private var filterState1 = 0.0
    private var filterState2 = 0.0

    // Simple reverb buffer
    private val reverbBuffer = FloatArray(SAMPLE_RATE / 4) { 0f }
    private var reverbPos = 0

    // Delay buffer
    private var delayBuffer = FloatArray(SAMPLE_RATE) { 0f }
    private var delayPos = 0

    private var isRunning = false

    init {
        val bufferBytes = AudioTrack.getMinBufferSize(
            SAMPLE_RATE,
            AudioFormat.CHANNEL_OUT_STEREO,
            AudioFormat.ENCODING_PCM_FLOAT
        ).coerceAtLeast(BUFFER_SIZE * 4)

        audioTrack = AudioTrack.Builder()
            .setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_MEDIA)
                    .setContentType(AudioAttributes.CONTENT_TYPE_MUSIC)
                    .build()
            )
            .setAudioFormat(
                AudioFormat.Builder()
                    .setSampleRate(SAMPLE_RATE)
                    .setEncoding(AudioFormat.ENCODING_PCM_FLOAT)
                    .setChannelMask(AudioFormat.CHANNEL_OUT_STEREO)
                    .build()
            )
            .setBufferSizeInBytes(bufferBytes)
            .setTransferMode(AudioTrack.MODE_STREAM)
            .build()

        audioTrack.play()
        isRunning = true
        startAudioLoop()
    }

    private fun startAudioLoop() {
        scope.launch {
            val buffer = FloatArray(BUFFER_SIZE * 2) // stereo
            while (isRunning) {
                generateSamples(buffer)
                audioTrack.write(buffer, 0, buffer.size, AudioTrack.WRITE_BLOCKING)
            }
        }
    }

    private fun generateSamples(buffer: FloatArray) {
        for (i in 0 until BUFFER_SIZE) {
            var sample = 0.0

            synchronized(lock) {
                val toRemove = mutableListOf<Int>()

                for ((noteId, state) in activeNotes) {
                    val noteSample = state.nextSample()
                    if (state.isFinished()) {
                        toRemove.add(noteId)
                    }
                    sample += noteSample
                }

                toRemove.forEach { activeNotes.remove(it) }
            }

            // Soft clip
            sample = tanh(sample * masterVolume)

            // Low-pass filter
            sample = applyFilter(sample)

            // Delay effect
            sample = applyDelay(sample.toFloat()).toDouble()

            // Reverb
            sample = applyReverb(sample.toFloat()).toDouble()

            val out = sample.toFloat().coerceIn(-1f, 1f)
            buffer[i * 2] = out       // left
            buffer[i * 2 + 1] = out   // right
        }
    }

    private fun applyFilter(input: Double): Double {
        if (filterCutoff >= 1.0f) return input
        val cutoff = filterCutoff.toDouble().coerceIn(0.001, 1.0)
        val f = 2.0 * sin(PI * cutoff * 0.5)
        val q = 1.0 - filterResonance.toDouble().coerceIn(0.0, 0.99)
        filterState1 += f * (input - filterState1 + q * (filterState1 - filterState2))
        filterState2 += f * (filterState1 - filterState2)
        return filterState2
    }

    private fun applyDelay(input: Float): Float {
        val delaySamples = ((delayTimeMs / 1000f) * SAMPLE_RATE).toInt().coerceIn(1, delayBuffer.size - 1)
        val readPos = (delayPos - delaySamples + delayBuffer.size) % delayBuffer.size
        val delayed = delayBuffer[readPos]
        delayBuffer[delayPos] = input + delayed * 0.4f
        delayPos = (delayPos + 1) % delayBuffer.size
        return input + delayed * delayMix
    }

    private fun applyReverb(input: Float): Float {
        val readPos = (reverbPos + 1) % reverbBuffer.size
        val reverbed = reverbBuffer[readPos]
        reverbBuffer[reverbPos] = input + reverbed * 0.6f
        reverbPos = (reverbPos + 1) % reverbBuffer.size
        return input + reverbed * reverbMix
    }

    fun noteOn(midiNote: Int, velocity: Float = 1.0f) {
        val freq = midiNoteToFreq(midiNote)
        synchronized(lock) {
            activeNotes[midiNote] = NoteState(freq, velocity, waveType, adsr)
        }
    }

    fun noteOff(midiNote: Int) {
        synchronized(lock) {
            activeNotes[midiNote]?.release()
        }
    }

    fun stopAllNotes() {
        synchronized(lock) {
            activeNotes.values.forEach { it.release() }
        }
    }

    fun release() {
        isRunning = false
        scope.cancel()
        audioTrack.stop()
        audioTrack.release()
    }

    private fun midiNoteToFreq(midiNote: Int): Double {
        return 440.0 * 2.0.pow((midiNote - 69) / 12.0)
    }
}

class NoteState(
    private val frequency: Double,
    private val velocity: Float,
    private val waveType: WaveType,
    private val adsr: AdsrEnvelope
) {
    private var phase = 0.0
    private val phaseIncrement = frequency * 2.0 * PI / SynthesizerEngine.SAMPLE_RATE

    private var sampleCount = 0L
    private var releaseStart = -1L
    private var releaseLevel = 0.0

    private val attackSamples = (adsr.attackMs / 1000f * SynthesizerEngine.SAMPLE_RATE).toLong().coerceAtLeast(1)
    private val decaySamples = (adsr.decayMs / 1000f * SynthesizerEngine.SAMPLE_RATE).toLong().coerceAtLeast(1)
    private val releaseSamples = (adsr.releaseMs / 1000f * SynthesizerEngine.SAMPLE_RATE).toLong().coerceAtLeast(1)

    fun nextSample(): Double {
        val env = envelope()
        val raw = when (waveType) {
            WaveType.SINE -> sin(phase)
            WaveType.SQUARE -> if (sin(phase) >= 0) 1.0 else -1.0
            WaveType.SAWTOOTH -> 2.0 * (phase / (2.0 * PI) - floor(phase / (2.0 * PI) + 0.5))
            WaveType.TRIANGLE -> 2.0 / PI * asin(sin(phase))
        }
        phase = (phase + phaseIncrement) % (2.0 * PI)
        sampleCount++
        return raw * env * velocity * 0.3
    }

    private fun envelope(): Double {
        if (releaseStart >= 0) {
            val t = sampleCount - releaseStart
            return if (t >= releaseSamples) 0.0
            else releaseLevel * (1.0 - t.toDouble() / releaseSamples)
        }
        return when {
            sampleCount < attackSamples ->
                sampleCount.toDouble() / attackSamples
            sampleCount < attackSamples + decaySamples -> {
                val t = (sampleCount - attackSamples).toDouble() / decaySamples
                1.0 - t * (1.0 - adsr.sustainLevel)
            }
            else -> adsr.sustainLevel.toDouble()
        }
    }

    fun release() {
        if (releaseStart < 0) {
            releaseLevel = envelope()
            releaseStart = sampleCount
        }
    }

    fun isFinished(): Boolean {
        if (releaseStart < 0) return false
        return sampleCount - releaseStart >= releaseSamples
    }
}
