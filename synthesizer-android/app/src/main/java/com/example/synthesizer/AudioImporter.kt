package com.example.synthesizer

import android.content.Context
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.net.Uri
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.nio.ByteBuffer
import java.nio.ByteOrder

data class ImportedAudio(
    val name: String,
    val uri: Uri,
    val durationMs: Long,
    val sampleRate: Int,
    val channelCount: Int,
    val pcmData: FloatArray
)

class AudioImporter(private val context: Context) {

    suspend fun importAudio(uri: Uri, displayName: String): Result<ImportedAudio> =
        withContext(Dispatchers.IO) {
            runCatching {
                val extractor = MediaExtractor()
                extractor.setDataSource(context, uri, null)

                val trackIndex = findAudioTrack(extractor)
                    ?: error("音声トラックが見つかりません")

                extractor.selectTrack(trackIndex)
                val format = extractor.getTrackFormat(trackIndex)

                val mime = format.getString(MediaFormat.KEY_MIME) ?: error("不明な形式")
                val durationUs = format.getLong(MediaFormat.KEY_DURATION)
                val sampleRate = format.getInteger(MediaFormat.KEY_SAMPLE_RATE)
                val channelCount = format.getInteger(MediaFormat.KEY_CHANNEL_COUNT)

                val pcmData = decode(extractor, format, mime)
                extractor.release()

                ImportedAudio(
                    name = displayName,
                    uri = uri,
                    durationMs = durationUs / 1000,
                    sampleRate = sampleRate,
                    channelCount = channelCount,
                    pcmData = pcmData
                )
            }
        }

    private fun findAudioTrack(extractor: MediaExtractor): Int? {
        for (i in 0 until extractor.trackCount) {
            val format = extractor.getTrackFormat(i)
            val mime = format.getString(MediaFormat.KEY_MIME) ?: continue
            if (mime.startsWith("audio/")) return i
        }
        return null
    }

    private fun decode(
        extractor: MediaExtractor,
        format: MediaFormat,
        mime: String
    ): FloatArray {
        val codec = MediaCodec.createDecoderByType(mime)
        codec.configure(format, null, null, 0)
        codec.start()

        val pcmSamples = mutableListOf<Float>()
        val info = MediaCodec.BufferInfo()
        var inputDone = false
        var outputDone = false

        val timeoutUs = 10_000L

        while (!outputDone) {
            if (!inputDone) {
                val inputIndex = codec.dequeueInputBuffer(timeoutUs)
                if (inputIndex >= 0) {
                    val inputBuffer = codec.getInputBuffer(inputIndex)!!
                    val sampleSize = extractor.readSampleData(inputBuffer, 0)
                    if (sampleSize < 0) {
                        codec.queueInputBuffer(inputIndex, 0, 0, 0, MediaCodec.BUFFER_FLAG_END_OF_STREAM)
                        inputDone = true
                    } else {
                        codec.queueInputBuffer(inputIndex, 0, sampleSize, extractor.sampleTime, 0)
                        extractor.advance()
                    }
                }
            }

            val outputIndex = codec.dequeueOutputBuffer(info, timeoutUs)
            if (outputIndex >= 0) {
                val outputBuffer = codec.getOutputBuffer(outputIndex)!!
                outputBuffer.order(ByteOrder.nativeOrder())

                val outputFormat = codec.getOutputFormat(outputIndex)
                @Suppress("DEPRECATION")
                val pcmEncodingKey = "pcm-encoding" // MediaFormat.KEY_PCM_ENCODING (API 24+)
                val encoding = if (outputFormat.containsKey(pcmEncodingKey))
                    outputFormat.getInteger(pcmEncodingKey)
                else
                    2 // ENCODING_PCM_16BIT

                when (encoding) {
                    4 -> { // ENCODING_PCM_FLOAT
                        val floatBuffer = outputBuffer.asFloatBuffer()
                        while (floatBuffer.hasRemaining()) {
                            pcmSamples.add(floatBuffer.get())
                        }
                    }
                    else -> { // PCM_16BIT
                        val shortBuffer = outputBuffer.asShortBuffer()
                        while (shortBuffer.hasRemaining()) {
                            pcmSamples.add(shortBuffer.get() / 32768f)
                        }
                    }
                }

                codec.releaseOutputBuffer(outputIndex, false)

                if (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) {
                    outputDone = true
                }
            }
        }

        codec.stop()
        codec.release()

        return pcmSamples.toFloatArray()
    }

    fun getSupportedExtensions() = listOf("mp3", "mp4", "m4a", "aac", "wav", "ogg", "flac")
}
