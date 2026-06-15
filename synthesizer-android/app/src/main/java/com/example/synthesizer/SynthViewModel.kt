package com.example.synthesizer

import android.app.Application
import android.net.Uri
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch

class SynthViewModel(app: Application) : AndroidViewModel(app) {

    val synthEngine = SynthesizerEngine()
    val samplePlayer = AudioSamplePlayer()

    val importedAudios = MutableLiveData<List<ImportedAudio>>(emptyList())
    val selectedAudio = MutableLiveData<ImportedAudio?>()
    val importStatus = MutableLiveData<String>()
    val useSampleMode = MutableLiveData(false)

    private val importer = AudioImporter(app)
    private val _audios = mutableListOf<ImportedAudio>()

    init {
        samplePlayer.start()
    }

    fun importAudio(uri: Uri, displayName: String) {
        viewModelScope.launch {
            importStatus.value = "インポート中: $displayName"
            importer.importAudio(uri, displayName)
                .onSuccess { audio ->
                    _audios.add(audio)
                    importedAudios.value = _audios.toList()
                    selectAudio(audio)
                    importStatus.value = "インポート完了: ${audio.name} (${audio.durationMs / 1000}秒)"
                }
                .onFailure { e ->
                    importStatus.value = "エラー: ${e.message}"
                }
        }
    }

    fun selectAudio(audio: ImportedAudio) {
        selectedAudio.value = audio
        samplePlayer.loadSample(audio)
        useSampleMode.value = true
    }

    fun setSynthMode() {
        useSampleMode.value = false
    }

    fun noteOn(midiNote: Int) {
        if (useSampleMode.value == true && selectedAudio.value != null) {
            samplePlayer.noteOn(midiNote)
        } else {
            synthEngine.noteOn(midiNote)
        }
    }

    fun noteOff(midiNote: Int) {
        if (useSampleMode.value == true && selectedAudio.value != null) {
            samplePlayer.noteOff(midiNote)
        } else {
            synthEngine.noteOff(midiNote)
        }
    }

    fun stopAllNotes() {
        synthEngine.stopAllNotes()
    }

    override fun onCleared() {
        super.onCleared()
        synthEngine.release()
        samplePlayer.release()
    }
}
