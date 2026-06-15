package com.example.synthesizer

import android.content.Intent
import android.database.Cursor
import android.net.Uri
import android.os.Bundle
import android.provider.OpenableColumns
import android.view.View
import android.widget.*
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.ViewModelProvider

class AudioImportActivity : AppCompatActivity() {

    private lateinit var viewModel: SynthViewModel
    private lateinit var listView: ListView
    private lateinit var statusText: TextView
    private lateinit var importButton: Button
    private lateinit var useButton: Button
    private lateinit var adapter: ArrayAdapter<String>

    private val audioNames = mutableListOf<String>()
    private var selectedIndex = -1

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        viewModel = ViewModelProvider(
            this,
            ViewModelProvider.AndroidViewModelFactory.getInstance(application)
        )[SynthViewModel::class.java]

        val layout = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(32, 32, 32, 32)
            setBackgroundColor(0xFF0D0D1A.toInt())
        }

        val title = TextView(this).apply {
            text = "音源インポート (MP3 / MP4 / M4A)"
            textSize = 18f
            setTextColor(0xFFFFFFFF.toInt())
            setPadding(0, 0, 0, 24)
        }

        importButton = Button(this).apply {
            text = "ファイルを選択"
            setBackgroundColor(0xFF00BCD4.toInt())
            setTextColor(0xFF000000.toInt())
            setOnClickListener { openFilePicker() }
        }

        statusText = TextView(this).apply {
            text = "ファイルを選択してください"
            textSize = 14f
            setTextColor(0xFFAAAAAA.toInt())
            setPadding(0, 16, 0, 16)
        }

        listView = ListView(this).apply {
            setBackgroundColor(0xFF1A1A2E.toInt())
        }

        adapter = ArrayAdapter(this, android.R.layout.simple_list_item_single_choice, audioNames)
        listView.adapter = adapter
        listView.choiceMode = ListView.CHOICE_MODE_SINGLE
        listView.setOnItemClickListener { _, _, position, _ ->
            selectedIndex = position
        }

        useButton = Button(this).apply {
            text = "この音源で演奏する"
            setBackgroundColor(0xFF4CAF50.toInt())
            setTextColor(0xFF000000.toInt())
            setOnClickListener { useSelectedAudio() }
        }

        val backButton = Button(this).apply {
            text = "シンセに戻る"
            setBackgroundColor(0xFF555577.toInt())
            setTextColor(0xFFFFFFFF.toInt())
            setOnClickListener { finish() }
        }

        layout.addView(title)
        layout.addView(importButton)
        layout.addView(statusText)
        layout.addView(listView, LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT, 400
        ))
        layout.addView(useButton)
        layout.addView(backButton)

        setContentView(layout)

        viewModel.importStatus.observe(this) { status ->
            statusText.text = status
        }

        viewModel.importedAudios.observe(this) { audios ->
            audioNames.clear()
            audioNames.addAll(audios.map { "${it.name} (${it.durationMs / 1000}秒)" })
            adapter.notifyDataSetChanged()
        }
    }

    private fun openFilePicker() {
        val intent = Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
            addCategory(Intent.CATEGORY_OPENABLE)
            type = "*/*"
            putExtra(Intent.EXTRA_MIME_TYPES, arrayOf(
                "audio/mpeg",   // MP3
                "audio/mp4",    // MP4 audio
                "video/mp4",    // MP4 video (extract audio)
                "audio/aac",
                "audio/x-wav",
                "audio/ogg",
                "audio/flac",
                "audio/m4a"
            ))
        }
        startActivityForResult(intent, REQUEST_AUDIO)
    }

    private fun useSelectedAudio() {
        val audios = viewModel.importedAudios.value ?: return
        if (selectedIndex < 0 || selectedIndex >= audios.size) {
            statusText.text = "音源を選択してください"
            return
        }
        viewModel.selectAudio(audios[selectedIndex])
        setResult(RESULT_OK)
        finish()
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == REQUEST_AUDIO && resultCode == RESULT_OK) {
            val uri = data?.data ?: return
            val name = getFileName(uri) ?: "音源ファイル"
            contentResolver.takePersistableUriPermission(
                uri, Intent.FLAG_GRANT_READ_URI_PERMISSION
            )
            viewModel.importAudio(uri, name)
        }
    }

    private fun getFileName(uri: Uri): String? {
        var name: String? = null
        if (uri.scheme == "content") {
            val cursor: Cursor? = contentResolver.query(uri, null, null, null, null)
            cursor?.use {
                if (it.moveToFirst()) {
                    val idx = it.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                    if (idx >= 0) name = it.getString(idx)
                }
            }
        }
        if (name == null) name = uri.lastPathSegment
        return name
    }

    companion object {
        const val REQUEST_AUDIO = 1001
    }
}
