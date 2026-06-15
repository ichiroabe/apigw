package com.example.synthesizer

import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioTrack
import kotlinx.coroutines.*
import kotlin.math.*

class AudioSamplePlayer {

    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())
    private val activePlayers = mutableMapOf<Int, SamplePlayState>()
    private val lock = Any()
    private var audioTrack: AudioTrack? = null
    private var isRunning = false

    private var importedAudio: ImportedAudio? = null
    private var basePitch = 60 // MIDI note C4 = root note of the sample

    fun loadSample(audio: ImportedAudio) {
        importedAudio = audio
    }

    fun start() {
        val bufferBytes = AudioTrack.getMinBufferSize(
            SynthesizerEngine.SAMPLE_RATE,
            AudioFormat.CHANNEL_OUT_STEREO,
            AudioFormat.ENCODING_PCM_FLOAT
        ).coerceAtLeast(SynthesizerEngine.BUFFER_SIZE * 4)

        audioTrack = AudioTrack.Builder()
            .setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_MEDIA)
                    .setContentType(AudioAttributes.CONTENT_TYPE_MUSIC)
                    .build()
            )
            .setAudioFormat(
                AudioFormat.Builder()
                    .setSampleRate(SynthesizerEngine.SAMPLE_RATE)
                    .setEncoding(AudioFormat.ENCODING_PCM_FLOAT)
                    .setChannelMask(AudioFormat.CHANNEL_OUT_STEREO)
                    .build()
            )
            .setBufferSizeInBytes(bufferBytes)
            .setTransferMode(AudioTrack.MODE_STREAM)
            .build()

        audioTrack?.play()
        isRunning = true

        scope.launch {
            val buffer = FloatArray(SynthesizerEngine.BUFFER_SIZE * 2)
            while (isRunning) {
                generateSamples(buffer)
                audioTrack?.write(buffer, 0, buffer.size, AudioTrack.WRITE_BLOCKING)
            }
        }
    }

    private fun generateSamples(buffer: FloatArray) {
        val audio = importedAudio ?: run {
            buffer.fill(0f)
            return
        }

        for (i in 0 until SynthesizerEngine.BUFFER_SIZE) {
            var sample = 0f
            synchronized(lock) {
                val toRemove = mutableListOf<Int>()
                for ((note, state) in activePlayers) {
                    val s = state.nextSample(audio)
                    if (state.isFinished(audio)) toRemove.add(note)
                    sample += s
                }
                toRemove.forEach { activePlayers.remove(it) }
            }
            val out = sample.coerceIn(-1f, 1f)
            buffer[i * 2] = out
            buffer[i * 2 + 1] = out
        }
    }

    fun noteOn(midiNote: Int) {
        val audio = importedAudio ?: return
        val semitones = midiNote - basePitch
        val ratio = 2.0.pow(semitones / 12.0).toFloat()
        val srcSampleRate = audio.sampleRate.toFloat()
        val dstSampleRate = SynthesizerEngine.SAMPLE_RATE.toFloat()
        val speed = ratio * srcSampleRate / dstSampleRate

        synchronized(lock) {
            activePlayers[midiNote] = SamplePlayState(speed, audio.channelCount)
        }
    }

    fun noteOff(midiNote: Int) {
        synchronized(lock) {
            activePlayers.remove(midiNote)
        }
    }

    fun release() {
        isRunning = false
        scope.cancel()
        audioTrack?.stop()
        audioTrack?.release()
        audioTrack = null
    }
}

class SamplePlayState(
    private val speed: Float,
    private val channelCount: Int
) {
    private var readPos = 0.0

    fun nextSample(audio: ImportedAudio): Float {
        val idx = readPos.toInt()
        val frac = (readPos - idx).toFloat()
        val srcIdx = idx * channelCount

        if (srcIdx + channelCount >= audio.pcmData.size) return 0f

        val s0 = audio.pcmData[srcIdx]
        val s1 = if (srcIdx + channelCount < audio.pcmData.size)
            audio.pcmData[srcIdx + channelCount] else s0

        readPos += speed
        return s0 + frac * (s1 - s0)
    }

    fun isFinished(audio: ImportedAudio): Boolean {
        return readPos.toInt() * channelCount >= audio.pcmData.size
    }
}
