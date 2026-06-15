package com.example.synthesizer

import android.content.Context
import android.graphics.*
import android.util.AttributeSet
import android.view.View
import kotlin.math.sin
import kotlin.math.PI

class WaveformView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : View(context, attrs) {

    var waveType = WaveType.SINE
        set(value) {
            field = value
            invalidate()
        }

    private val wavePaint = Paint().apply {
        color = Color.parseColor("#00BCD4")
        style = Paint.Style.STROKE
        strokeWidth = 3f
        isAntiAlias = true
    }
    private val bgPaint = Paint().apply {
        color = Color.parseColor("#1A1A2E")
    }
    private val gridPaint = Paint().apply {
        color = Color.parseColor("#2A2A4E")
        style = Paint.Style.STROKE
        strokeWidth = 1f
    }
    private val glowPaint = Paint().apply {
        color = Color.parseColor("#4000BCD4")
        style = Paint.Style.STROKE
        strokeWidth = 8f
        isAntiAlias = true
        maskFilter = BlurMaskFilter(10f, BlurMaskFilter.Blur.NORMAL)
    }

    private val path = Path()
    private val glowPath = Path()

    override fun onDraw(canvas: Canvas) {
        val w = width.toFloat()
        val h = height.toFloat()
        val mid = h / 2f

        canvas.drawRect(0f, 0f, w, h, bgPaint)

        // Grid
        canvas.drawLine(0f, mid, w, mid, gridPaint)
        canvas.drawLine(w / 2, 0f, w / 2, h, gridPaint)

        val cycles = 2
        val points = 512
        path.reset()
        glowPath.reset()

        for (i in 0..points) {
            val t = i.toFloat() / points
            val x = t * w
            val phase = t * cycles * 2f * PI.toFloat()

            val y = mid - mid * 0.7f * when (waveType) {
                WaveType.SINE -> sin(phase)
                WaveType.SQUARE -> if (sin(phase) >= 0) 1f else -1f
                WaveType.SAWTOOTH -> (phase % (2f * PI.toFloat())) / PI.toFloat() - 1f
                WaveType.TRIANGLE -> 2f / PI.toFloat() * kotlin.math.asin(sin(phase))
            }

            if (i == 0) {
                path.moveTo(x, y)
                glowPath.moveTo(x, y)
            } else {
                path.lineTo(x, y)
                glowPath.lineTo(x, y)
            }
        }

        canvas.drawPath(glowPath, glowPaint)
        canvas.drawPath(path, wavePaint)
    }
}
