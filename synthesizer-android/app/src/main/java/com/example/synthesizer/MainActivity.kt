package com.example.synthesizer

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.widget.*
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.ViewModelProvider

class MainActivity : AppCompatActivity() {

    private lateinit var viewModel: SynthViewModel
    private lateinit var pianoView: PianoKeyboardView
    private lateinit var waveformView: WaveformView
    private lateinit var statusText: TextView
    private lateinit var modeLabel: TextView

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { /* permissions granted/denied handled gracefully */ }

    private val importLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == RESULT_OK) {
            updateModeLabel()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        viewModel = ViewModelProvider(
            this,
            ViewModelProvider.AndroidViewModelFactory.getInstance(application)
        )[SynthViewModel::class.java]

        requestPermissions()
        setContentView(buildLayout())
        setupObservers()
    }

    private fun buildLayout(): ScrollView {
        val root = ScrollView(this).apply {
            setBackgroundColor(0xFF0D0D1A.toInt())
        }

        val container = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(16, 16, 16, 16)
        }

        // ── Header ──────────────────────────────────────
        val header = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
        }
        val appTitle = TextView(this).apply {
            text = "Synthesizer"
            textSize = 22f
            setTextColor(0xFF00BCD4.toInt())
            setPadding(0, 0, 0, 0)
            layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
        }
        modeLabel = TextView(this).apply {
            text = "モード: シンセ"
            textSize = 13f
            setTextColor(0xFF90CAF9.toInt())
            gravity = android.view.Gravity.END
        }
        header.addView(appTitle)
        header.addView(modeLabel)

        // ── Waveform display ────────────────────────────
        waveformView = WaveformView(this).apply {
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT, 140
            ).also { it.topMargin = 12 }
        }

        // ── Wave type selector ──────────────────────────
        val waveRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(0, 12, 0, 4)
        }
        val waveLabel = TextView(this).apply {
            text = "波形: "
            textSize = 14f
            setTextColor(0xFFCCCCCC.toInt())
            gravity = android.view.Gravity.CENTER_VERTICAL
        }
        val waveGroup = RadioGroup(this).apply {
            orientation = RadioGroup.HORIZONTAL
        }
        val waves = listOf("サイン" to WaveType.SINE, "矩形" to WaveType.SQUARE,
            "のこぎり" to WaveType.SAWTOOTH, "三角" to WaveType.TRIANGLE)
        waves.forEachIndexed { idx, (label, type) ->
            RadioButton(this).apply {
                text = label
                id = idx + 1
                setTextColor(0xFFFFFFFF.toInt())
                isChecked = idx == 0
                setOnCheckedChangeListener { _, checked ->
                    if (checked) {
                        viewModel.synthEngine.waveType = type
                        waveformView.waveType = type
                    }
                }
                waveGroup.addView(this)
            }
        }
        waveRow.addView(waveLabel)
        waveRow.addView(waveGroup)

        // ── ADSR sliders ────────────────────────────────
        val adsrLayout = buildAdsrPanel()

        // ── Effects panel ───────────────────────────────
        val fxLayout = buildEffectsPanel()

        // ── Import / mode buttons ───────────────────────
        val btnRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(0, 12, 0, 12)
        }
        val importBtn = Button(this).apply {
            text = "音源インポート\n(MP3/MP4)"
            setBackgroundColor(0xFF7B1FA2.toInt())
            setTextColor(0xFFFFFFFF.toInt())
            setPadding(24, 12, 24, 12)
            layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
                .also { it.marginEnd = 8 }
            setOnClickListener {
                importLauncher.launch(Intent(this@MainActivity, AudioImportActivity::class.java))
            }
        }
        val synthBtn = Button(this).apply {
            text = "シンセモード\nに切替"
            setBackgroundColor(0xFF1565C0.toInt())
            setTextColor(0xFFFFFFFF.toInt())
            setPadding(24, 12, 24, 12)
            layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
            setOnClickListener {
                viewModel.setSynthMode()
                updateModeLabel()
            }
        }
        btnRow.addView(importBtn)
        btnRow.addView(synthBtn)

        // ── Status text ─────────────────────────────────
        statusText = TextView(this).apply {
            text = "準備完了"
            textSize = 13f
            setTextColor(0xFF888888.toInt())
            setPadding(0, 4, 0, 8)
        }

        // ── Piano keyboard ──────────────────────────────
        pianoView = PianoKeyboardView(this).apply {
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT, 220
            ).also { it.topMargin = 8 }
            keyListener = object : PianoKeyboardView.KeyListener {
                override fun onKeyDown(midiNote: Int) = viewModel.noteOn(midiNote)
                override fun onKeyUp(midiNote: Int) = viewModel.noteOff(midiNote)
            }
        }

        container.addView(header)
        container.addView(waveformView)
        container.addView(waveRow)
        container.addView(adsrLayout)
        container.addView(fxLayout)
        container.addView(btnRow)
        container.addView(statusText)
        container.addView(pianoView)
        root.addView(container)
        return root
    }

    private fun buildAdsrPanel(): LinearLayout {
        val panel = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, 8, 0, 0)
        }
        val title = TextView(this).apply {
            text = "ADSR エンベロープ"
            textSize = 13f
            setTextColor(0xFF90CAF9.toInt())
        }
        panel.addView(title)

        fun addSlider(label: String, min: Int, max: Int, initial: Int, onChange: (Int) -> Unit) {
            val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
            val lbl = TextView(this).apply {
                text = label
                textSize = 12f
                setTextColor(0xFFCCCCCC.toInt())
                layoutParams = LinearLayout.LayoutParams(160, LinearLayout.LayoutParams.WRAP_CONTENT)
                gravity = android.view.Gravity.CENTER_VERTICAL
            }
            val seek = SeekBar(this).apply {
                this.max = max - min
                progress = initial - min
                layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
                setOnSeekBarChangeListener(object : SeekBar.OnSeekBarChangeListener {
                    override fun onProgressChanged(sb: SeekBar, p: Int, byUser: Boolean) = onChange(p + min)
                    override fun onStartTrackingTouch(sb: SeekBar) {}
                    override fun onStopTrackingTouch(sb: SeekBar) {}
                })
            }
            row.addView(lbl)
            row.addView(seek)
            panel.addView(row)
        }

        addSlider("A (ms): 10", 1, 2000, 10) { viewModel.synthEngine.adsr.attackMs = it.toFloat() }
        addSlider("D (ms): 100", 1, 2000, 100) { viewModel.synthEngine.adsr.decayMs = it.toFloat() }
        addSlider("S: 70%", 0, 100, 70) { viewModel.synthEngine.adsr.sustainLevel = it / 100f }
        addSlider("R (ms): 200", 1, 3000, 200) { viewModel.synthEngine.adsr.releaseMs = it.toFloat() }

        return panel
    }

    private fun buildEffectsPanel(): LinearLayout {
        val panel = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, 8, 0, 0)
        }
        val title = TextView(this).apply {
            text = "エフェクト"
            textSize = 13f
            setTextColor(0xFF90CAF9.toInt())
        }
        panel.addView(title)

        fun addSlider(label: String, onChange: (Float) -> Unit) {
            val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
            val lbl = TextView(this).apply {
                text = label
                textSize = 12f
                setTextColor(0xFFCCCCCC.toInt())
                layoutParams = LinearLayout.LayoutParams(200, LinearLayout.LayoutParams.WRAP_CONTENT)
                gravity = android.view.Gravity.CENTER_VERTICAL
            }
            val seek = SeekBar(this).apply {
                max = 100
                progress = 0
                layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
                setOnSeekBarChangeListener(object : SeekBar.OnSeekBarChangeListener {
                    override fun onProgressChanged(sb: SeekBar, p: Int, byUser: Boolean) = onChange(p / 100f)
                    override fun onStartTrackingTouch(sb: SeekBar) {}
                    override fun onStopTrackingTouch(sb: SeekBar) {}
                })
            }
            row.addView(lbl)
            row.addView(seek)
            panel.addView(row)
        }

        addSlider("音量") { viewModel.synthEngine.masterVolume = it.coerceAtLeast(0.01f) }
        addSlider("フィルター") { viewModel.synthEngine.filterCutoff = 1f - it * 0.99f }
        addSlider("リバーブ") { viewModel.synthEngine.reverbMix = it * 0.6f }
        addSlider("ディレイ") { viewModel.synthEngine.delayMix = it * 0.8f }

        return panel
    }

    private fun setupObservers() {
        viewModel.importStatus.observe(this) { statusText.text = it }
        viewModel.useSampleMode.observe(this) { updateModeLabel() }
    }

    private fun updateModeLabel() {
        val isSample = viewModel.useSampleMode.value == true
        val audioName = viewModel.selectedAudio.value?.name ?: ""
        modeLabel.text = if (isSample) "モード: サンプル ($audioName)" else "モード: シンセ"
    }

    private fun requestPermissions() {
        val perms = mutableListOf<String>()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_MEDIA_AUDIO)
                != PackageManager.PERMISSION_GRANTED) {
                perms.add(Manifest.permission.READ_MEDIA_AUDIO)
            }
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_MEDIA_VIDEO)
                != PackageManager.PERMISSION_GRANTED) {
                perms.add(Manifest.permission.READ_MEDIA_VIDEO)
            }
        } else {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_EXTERNAL_STORAGE)
                != PackageManager.PERMISSION_GRANTED) {
                perms.add(Manifest.permission.READ_EXTERNAL_STORAGE)
            }
        }
        if (perms.isNotEmpty()) permissionLauncher.launch(perms.toTypedArray())
    }
}
