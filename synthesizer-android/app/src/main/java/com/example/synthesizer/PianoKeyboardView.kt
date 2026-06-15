package com.example.synthesizer

import android.content.Context
import android.graphics.*
import android.util.AttributeSet
import android.view.MotionEvent
import android.view.View

class PianoKeyboardView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : View(context, attrs) {

    interface KeyListener {
        fun onKeyDown(midiNote: Int)
        fun onKeyUp(midiNote: Int)
    }

    var keyListener: KeyListener? = null

    // Start from C3 (MIDI 48), show 3 octaves
    private val startNote = 48
    private val octaves = 3
    private val whiteKeysPerOctave = 7
    private val totalWhiteKeys = octaves * whiteKeysPerOctave

    // White key indices within an octave: C D E F G A B
    private val whiteKeyOffsets = intArrayOf(0, 2, 4, 5, 7, 9, 11)
    // Black key positions (relative to white keys): C# D# F# G# A#
    private val blackKeyOffsets = intArrayOf(1, 3, 6, 8, 10)
    private val blackKeyWhitePositions = intArrayOf(0, 1, 3, 4, 5) // after which white key

    private var whiteKeyWidth = 0f
    private var whiteKeyHeight = 0f
    private var blackKeyWidth = 0f
    private var blackKeyHeight = 0f

    private val whitePaint = Paint().apply {
        color = Color.WHITE
        style = Paint.Style.FILL
    }
    private val whiteStrokePaint = Paint().apply {
        color = Color.LTGRAY
        style = Paint.Style.STROKE
        strokeWidth = 1.5f
    }
    private val blackPaint = Paint().apply {
        color = Color.parseColor("#222222")
        style = Paint.Style.FILL
    }
    private val pressedWhitePaint = Paint().apply {
        color = Color.parseColor("#90CAF9")
        style = Paint.Style.FILL
    }
    private val pressedBlackPaint = Paint().apply {
        color = Color.parseColor("#1565C0")
        style = Paint.Style.FILL
    }
    private val labelPaint = Paint().apply {
        color = Color.GRAY
        textSize = 24f
        textAlign = Paint.Align.CENTER
        typeface = Typeface.DEFAULT_BOLD
    }

    private val pressedNotes = mutableSetOf<Int>()
    private val touchNoteMap = mutableMapOf<Int, Int>() // pointer id -> midi note

    override fun onSizeChanged(w: Int, h: Int, oldw: Int, oldh: Int) {
        super.onSizeChanged(w, h, oldw, oldh)
        whiteKeyWidth = w.toFloat() / totalWhiteKeys
        whiteKeyHeight = h.toFloat()
        blackKeyWidth = whiteKeyWidth * 0.65f
        blackKeyHeight = whiteKeyHeight * 0.62f
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)

        // Draw white keys
        var whiteIndex = 0
        for (oct in 0 until octaves) {
            for (wi in 0 until whiteKeysPerOctave) {
                val midiNote = startNote + oct * 12 + whiteKeyOffsets[wi]
                val x = whiteIndex * whiteKeyWidth
                val rect = RectF(x + 1, 0f, x + whiteKeyWidth - 1, whiteKeyHeight - 1)

                canvas.drawRoundRect(rect, 6f, 6f,
                    if (midiNote in pressedNotes) pressedWhitePaint else whitePaint)
                canvas.drawRoundRect(rect, 6f, 6f, whiteStrokePaint)

                // Label every C
                if (wi == 0) {
                    val noteName = "C${(midiNote / 12) - 1}"
                    canvas.drawText(noteName, x + whiteKeyWidth / 2, whiteKeyHeight - 12f, labelPaint)
                }

                whiteIndex++
            }
        }

        // Draw black keys on top
        whiteIndex = 0
        for (oct in 0 until octaves) {
            for (wi in 0 until whiteKeysPerOctave) {
                // Draw black key after this white key if applicable
                val blackOffset = blackKeyWhitePositions.indexOf(wi)
                if (blackOffset >= 0) {
                    val midiNote = startNote + oct * 12 + blackKeyOffsets[blackOffset]
                    val x = (whiteIndex + 1) * whiteKeyWidth - blackKeyWidth / 2
                    val rect = RectF(x, 0f, x + blackKeyWidth, blackKeyHeight)
                    canvas.drawRoundRect(rect, 4f, 4f,
                        if (midiNote in pressedNotes) pressedBlackPaint else blackPaint)
                }
                whiteIndex++
            }
        }
    }

    override fun onTouchEvent(event: MotionEvent): Boolean {
        val pointerIndex = event.actionIndex
        val pointerId = event.getPointerId(pointerIndex)

        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN, MotionEvent.ACTION_POINTER_DOWN -> {
                val x = event.getX(pointerIndex)
                val y = event.getY(pointerIndex)
                val note = getNoteAt(x, y) ?: return true
                touchNoteMap[pointerId] = note
                pressedNotes.add(note)
                keyListener?.onKeyDown(note)
                invalidate()
            }
            MotionEvent.ACTION_UP, MotionEvent.ACTION_POINTER_UP, MotionEvent.ACTION_CANCEL -> {
                val note = touchNoteMap.remove(pointerId)
                if (note != null) {
                    pressedNotes.remove(note)
                    keyListener?.onKeyUp(note)
                    invalidate()
                }
            }
            MotionEvent.ACTION_MOVE -> {
                for (i in 0 until event.pointerCount) {
                    val pid = event.getPointerId(i)
                    val x = event.getX(i)
                    val y = event.getY(i)
                    val newNote = getNoteAt(x, y) ?: continue
                    val oldNote = touchNoteMap[pid]
                    if (newNote != oldNote) {
                        if (oldNote != null) {
                            pressedNotes.remove(oldNote)
                            keyListener?.onKeyUp(oldNote)
                        }
                        touchNoteMap[pid] = newNote
                        pressedNotes.add(newNote)
                        keyListener?.onKeyDown(newNote)
                        invalidate()
                    }
                }
            }
        }
        return true
    }

    private fun getNoteAt(x: Float, y: Float): Int? {
        // Check black keys first (they're on top)
        var whiteIndex = 0
        for (oct in 0 until octaves) {
            for (wi in 0 until whiteKeysPerOctave) {
                val blackOffset = blackKeyWhitePositions.indexOf(wi)
                if (blackOffset >= 0 && y < blackKeyHeight) {
                    val bx = (whiteIndex + 1) * whiteKeyWidth - blackKeyWidth / 2
                    if (x >= bx && x <= bx + blackKeyWidth) {
                        return startNote + oct * 12 + blackKeyOffsets[blackOffset]
                    }
                }
                whiteIndex++
            }
        }

        // Check white keys
        val wi = (x / whiteKeyWidth).toInt().coerceIn(0, totalWhiteKeys - 1)
        val oct = wi / whiteKeysPerOctave
        val noteInOctave = wi % whiteKeysPerOctave
        return startNote + oct * 12 + whiteKeyOffsets[noteInOctave]
    }
}
