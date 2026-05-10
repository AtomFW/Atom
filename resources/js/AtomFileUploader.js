class AtomFileUploader {
    constructor(options = {}) {
        this.options = $.extend(true, {
            container: null,                 // e.g. '#uploadBox' or null
            endpoints: {
                start: '/upload.php?action=start',
                chunk: '/upload.php?action=chunk',
                finalize: '/upload.php?action=finalize',
                pause: '/upload.php?action=pause',
                resume: '/upload.php?action=resume',
                cancel: '/upload.php?action=cancel',
                status: '/upload.php?action=status',
                upload: '/upload.php?action=upload'
            },

            fieldNames: {
                files: 'files',
                file: 'file',
                uploadId: 'upload_id',
                chunkIndex: 'chunk_index'
            },

            multiple: true,
            maxFiles: 10,

            chunked: true,
            chunkSize: 100 * 1024, // 100 KB
            pauseResume: true,
            cancelEnabled: true,

            maxFileSize: 50 * 1024 * 1024,   // 50 MB
            minFileSize: 1,
            maxTotalSize: 200 * 1024 * 1024, // 200 MB together

            allowedExtensions: [],           // e.g. ['jpg','png','pdf']
            deniedExtensions: [],            // e.g. ['php','exe','js']
            allowedMimeTypes: [],            // e.g. ['image/jpeg','image/png']
            deniedMimeTypes: [],

            allowedNames: [],                // exact names
            blockedNames: [],                // exact names
            namePattern: null,               // e.g. /^[a-z0-9_\-\.]+$/i

            sanitizeFilename: true,
            overwriteExisting: false,

            requestHeaders: {},
            extraData: {},                   // additional fields for the backend

            callbacks: {
                beforeUpload: null,          // (file, uploader) => bool|void
                beforeStart: null,           // (file, uploader) => bool|void
                onStart: null,               // (file, state) => void
                onProgress: null,            // (file, progress, state) => void
                onSuccess: null,             // (file, response, state) => void
                onError: null,               // (file, error, state) => void
                onComplete: null,            // (file, state) => void
                onCancel: null,              // (state) => void
                onPause: null,               // (state) => void
                onResume: null,              // (state) => void,
                onRejected: null             // (file, reasons) => void
            }
        }, options);

        this.$bus = $(this.options.container || {});
        this.activeUploads = new Map(); // uploadId => state
    }

    on(eventName, handler) {
        this.$bus.on(eventName, handler);
        return this;
    }

    off(eventName, handler) {
        this.$bus.off(eventName, handler);
        return this;
    }

    trigger(eventName, detail = {}) {
        this.$bus.trigger(eventName, detail);
        return this;
    }

    setAllowedExtensions(list) {
        this.options.allowedExtensions = this._normalizeStringArray(list);
        return this;
    }

    setDeniedExtensions(list) {
        this.options.deniedExtensions = this._normalizeStringArray(list);
        return this;
    }

    setAllowedNames(list) {
        this.options.allowedNames = this._normalizeStringArray(list);
        return this;
    }

    setBlockedNames(list) {
        this.options.blockedNames = this._normalizeStringArray(list);
        return this;
    }

    setAllowedMimeTypes(list) {
        this.options.allowedMimeTypes = this._normalizeStringArray(list);
        return this;
    }

    setDeniedMimeTypes(list) {
        this.options.deniedMimeTypes = this._normalizeStringArray(list);
        return this;
    }

    setMaxFileSize(bytes) {
        this.options.maxFileSize = Number(bytes) || this.options.maxFileSize;
        return this;
    }

    setMaxTotalSize(bytes) {
        this.options.maxTotalSize = Number(bytes) || this.options.maxTotalSize;
        return this;
    }

    setMaxFiles(count) {
        this.options.maxFiles = Number(count) || this.options.maxFiles;
        return this;
    }

    setChunkSize(bytes) {
        this.options.chunkSize = Math.max(1024, Number(bytes) || this.options.chunkSize);
        return this;
    }

    enableMultiple(flag = true) {
        this.options.multiple = !!flag;
        return this;
    }

    enableChunked(flag = true) {
        this.options.chunked = !!flag;
        return this;
    }

    enablePauseResume(flag = true) {
        this.options.pauseResume = !!flag;
        return this;
    }

    enableCancel(flag = true) {
        this.options.cancelEnabled = !!flag;
        return this;
    }

    setEndpoints(endpoints = {}) {
        this.options.endpoints = $.extend(true, {}, this.options.endpoints, endpoints);
        return this;
    }

    setExtraData(data = {}) {
        this.options.extraData = $.extend(true, {}, data);
        return this;
    }

    reset() {
        this.activeUploads.clear();
        return this;
    }

    destroy() {
        this.reset();
        this.$bus.off();
        return this;
    }

    async upload(inputOrFiles, meta = {}) {
        const files = this._normalizeInput(inputOrFiles);

        if (!files.length) {
            return this._failGlobal('No files to send.');
        }

        if (!this.options.multiple && files.length > 1) {
            return this._failGlobal('Multiple file uploads are disabled.');
        }

        if (files.length > this.options.maxFiles) {
            return this._failGlobal('Maximum number of files exceeded.');
        }

        const totalSize = files.reduce((sum, f) => sum + (f.size || 0), 0);
        if (totalSize > this.options.maxTotalSize) {
            return this._failGlobal('Maximum total file size exceeded.');
        }

        const results = [];

        for (const file of files) {
            const validation = this.validateFile(file);
            if (!validation.ok) {
                this._emitRejected(file, validation.reasons);
                return validation;
            }

            const beforeUploadResult = this._callCallback('beforeUpload', file, this);
            if (beforeUploadResult === false) {
                const reason = ['Upload stopped by beforeUpload.'];
                this._emitRejected(file, reason);
                return this._fail(file, reason.join(' '), { stage: 'beforeUpload' });
            }

            const beforeStartResult = this._callCallback('beforeStart', file, this);
            if (beforeStartResult === false) {
                const reason = ['Upload stopped by beforeStart.'];
                this._emitRejected(file, reason);
                return this._fail(file, reason.join(' '), { stage: 'beforeStart' });
            }

            if (this.options.chunked && file.size > this.options.chunkSize) {
                const res = await this._uploadChunked(file, meta);
                if (!res.ok) return res;
                results.push(res.data);
            } else {
                const res = await this._uploadStandard(file, meta);
                if (!res.ok) return res;
                results.push(res.data);
            }
        }

        return this._ok({ files: results });
    }

    async uploadFromInput(inputSelector, meta = {}) {
        const el = $(inputSelector).get(0);
        if (!el || !el.files) {
            return this._failGlobal('Invalid file input.');
        }
        return this.upload(el.files, meta);
    }

    async uploadSingle(file, meta = {}) {
        const files = this._normalizeInput(file);
        if (files.length !== 1) {
            return this._failGlobal('Exactly one file expected.');
        }
        return this.upload(files, meta);
    }

    validateFile(file) {
        const reasons = [];

        if (!file) {
            reasons.push('File missing.');
            return { ok: false, reasons };
        }

        const name = this._getSafeName(file.name || '');
        const size = Number(file.size || 0);
        const ext = this._getExtension(name);
        const mime = String(file.type || '').toLowerCase();

        if (!name) reasons.push('Empty or invalid file name.');
        if (size < this.options.minFileSize) reasons.push('The file is too small.');
        if (size > this.options.maxFileSize) reasons.push('The file exceeds the maximum size.');

        if (this.options.allowedExtensions.length && !this.options.allowedExtensions.includes(ext)) {
            reasons.push(`Illegal extension: ${ext || '(lack)'}.`);
        }
        if (this.options.deniedExtensions.includes(ext)) {
            reasons.push(`Extension blocked: ${ext || '(lack)'}.`);
        }

        if (this.options.allowedMimeTypes.length && mime && !this.options.allowedMimeTypes.includes(mime)) {
            reasons.push(`Illegal MIME: ${mime}.`);
        }
        if (this.options.deniedMimeTypes.includes(mime)) {
            reasons.push(`MIME blocked: ${mime}.`);
        }

        if (this.options.allowedNames.length && !this.options.allowedNames.includes(name)) {
            reasons.push(`File name is not allowed: ${name}.`);
        }
        if (this.options.blockedNames.includes(name)) {
            reasons.push(`The file name is blocked: ${name}.`);
        }

        if (this.options.namePattern && !(this.options.namePattern instanceof RegExp)) {
            reasons.push('namePattern must be of type RegExp.');
        } else if (this.options.namePattern && !this.options.namePattern.test(name)) {
            reasons.push('File name does not match the pattern.');
        }

        return reasons.length ? { ok: false, reasons } : { ok: true, reasons: [] };
    }

    async pause(uploadId) {
        if (!this.options.pauseResume) {
            return this._failGlobal('Pause/resume is disabled.');
        }

        const state = this.activeUploads.get(uploadId);
        if (!state) return this._failGlobal('No active upload found.');

        state.paused = true;
        this.activeUploads.set(uploadId, state);

        await this._postJSON(this.options.endpoints.pause, { [this.options.fieldNames.uploadId]: uploadId });
        this._callCallback('onPause', state);
        this.trigger('upload:pause', { uploadId, state });

        return this._ok({ uploadId, status: 'paused' });
    }

    async resume(uploadId) {
        if (!this.options.pauseResume) {
            return this._failGlobal('Pause/resume is disabled.');
        }

        const state = this.activeUploads.get(uploadId);
        if (!state) return this._failGlobal('No active upload found.');

        state.paused = false;
        this.activeUploads.set(uploadId, state);

        await this._postJSON(this.options.endpoints.resume, { [this.options.fieldNames.uploadId]: uploadId });
        this._callCallback('onResume', state);
        this.trigger('upload:resume', { uploadId, state });

        return this._ok({ uploadId, status: 'uploading' });
    }

    async cancel(uploadId) {
        if (!this.options.cancelEnabled) {
            return this._failGlobal('Cancellation is disabled.');
        }

        const state = this.activeUploads.get(uploadId);
        if (state) state.cancelled = true;

        await this._postJSON(this.options.endpoints.cancel, { [this.options.fieldNames.uploadId]: uploadId });
        this.activeUploads.delete(uploadId);

        this._callCallback('onCancel', state || { uploadId });
        this.trigger('upload:cancel', { uploadId, state });

        return this._ok({ uploadId, status: 'cancelled' });
    }

    async status(uploadId) {
        return this._postJSON(this.options.endpoints.status, { [this.options.fieldNames.uploadId]: uploadId });
    }

    humanBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        let i = 0;
        let value = Number(bytes) || 0;

        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i++;
        }

        return `${value.toFixed(value >= 10 ? 0 : 2)} ${units[i]}`;
    }

    // -----------------------
    // Internals
    // -----------------------

    _normalizeInput(inputOrFiles) {
        if (!inputOrFiles) return [];

        if (inputOrFiles instanceof File) {
            return [inputOrFiles];
        }

        if (inputOrFiles instanceof FileList || Array.isArray(inputOrFiles)) {
            return Array.from(inputOrFiles);
        }

        if (inputOrFiles.files instanceof FileList) {
            return Array.from(inputOrFiles.files);
        }

        return [];
    }

    _normalizeStringArray(list) {
        if (!Array.isArray(list)) return [];
        return list
            .map(v => String(v).trim().toLowerCase())
            .filter(Boolean);
    }

    _getSafeName(name) {
        name = String(name || '').trim();

        if (!this.options.sanitizeFilename) return name;

        name = name.replace(/[\0\r\n\t]/g, '');
        name = name.split(/[\/\\]/).pop();
        name = name.replace(/[^\p{L}\p{N}._ -]+/gu, '_');
        name = name.replace(/\s+/g, '_');
        name = name.replace(/_+/g, '_');
        name = name.replace(/^[_\-. ]+|[_\-. ]+$/g, '');

        return name;
    }

    _getExtension(name) {
        const parts = String(name || '').toLowerCase().split('.');
        if (parts.length < 2) return '';
        return parts.pop();
    }

    _randomId() {
        return `${Date.now()}_${Math.random().toString(16).slice(2)}_${Math.random().toString(16).slice(2)}`;
    }

    _callCallback(name, ...args) {
        const fn = this.options.callbacks && this.options.callbacks[name];
        if (typeof fn !== 'function') return undefined;
        try {
            return fn(...args);
        } catch (err) {
            console.error(`Callback ${name} error:`, err);
            return undefined;
        }
    }

    _emitRejected(file, reasons) {
        const payload = { file, reasons };
        this._callCallback('onRejected', file, reasons);
        this.trigger('upload:rejected', payload);
    }

    _ok(data) {
        return { ok: true, data };
    }

    _fail(fileOrNull, error, extra = {}) {
        const payload = { ok: false, error, ...extra };
        if (fileOrNull) {
            this._callCallback('onError', fileOrNull, error, extra.state || null);
            this.trigger('upload:error', { file: fileOrNull, error, extra });
        }
        return payload;
    }

    _failGlobal(error) {
        this.trigger('upload:error', { error });
        return { ok: false, error };
    }

    _ajax(options) {
        return new Promise((resolve, reject) => {
            $.ajax($.extend(true, {
                method: 'POST',
                timeout: 0,
                success: resolve,
                error: (xhr, status, err) => reject({ xhr, status, err })
            }, options));
        });
    }

    async _postJSON(url, data) {
        return this._ajax({
            url,
            data,
            dataType: 'json'
        });
    }

    async _uploadStandard(file, meta = {}) {
        const uploadId = this._randomId();
        const state = {
            uploadId,
            file,
            chunked: false,
            progress: 0,
            paused: false,
            cancelled: false,
            startedAt: Date.now()
        };

        this.activeUploads.set(uploadId, state);
        this._callCallback('onStart', file, state);
        this.trigger('upload:start', { file, state });

        try {
            const formData = new FormData();
            formData.append(this.options.fieldNames.file, file);

            Object.entries(this.options.extraData || {}).forEach(([k, v]) => formData.append(k, v));
            Object.entries(meta || {}).forEach(([k, v]) => formData.append(k, v));
            formData.append('upload_id', uploadId);

            const response = await this._ajax({
                url: this.options.endpoints.upload,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: this.options.requestHeaders,
                xhr: () => {
                    const xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.onprogress = (evt) => {
                            if (!evt.lengthComputable) return;
                            const progress = Math.round((evt.loaded / evt.total) * 100);
                            state.progress = progress;
                            this.activeUploads.set(uploadId, state);
                            this._callCallback('onProgress', file, progress, state);
                            this.trigger('upload:progress', { file, progress, state });
                        };
                    }
                    return xhr;
                }
            });

            state.progress = 100;
            this.activeUploads.delete(uploadId);

            this._callCallback('onSuccess', file, response, state);
            this._callCallback('onComplete', file, state);
            this.trigger('upload:success', { file, response, state });
            this.trigger('upload:complete', { file, state });

            return this._ok({
                uploadId,
                file,
                response,
                mode: 'standard'
            });
        } catch (err) {
            this.activeUploads.delete(uploadId);
            const error = this._normalizeAjaxError(err);
            this._callCallback('onError', file, error, state);
            this._callCallback('onComplete', file, state);
            this.trigger('upload:error', { file, error, state });
            this.trigger('upload:complete', { file, state });

            return this._fail(file, error, { state });
        }
    }

    async _uploadChunked(file, meta = {}) {
        const totalChunks = Math.ceil(file.size / this.options.chunkSize);
        let uploadId = this._randomId();

        const startResp = await this._postJSON(this.options.endpoints.start, {
            [this.options.fieldNames.uploadId]: uploadId,
            filename: file.name,
            total_size: file.size,
            total_chunks: totalChunks,
            title: meta.title || file.name.replace(/\.[^.]+$/, ''),
            ...this.options.extraData,
            ...meta
        });

        if (!startResp || startResp.ok === false || !startResp?.data?.upload_id) {
            return this._fail(file, startResp?.error || 'Failed to start chunk session.');
        }

        const state = {
            uploadId: startResp.data.upload_id,
            file,
            chunked: true,
            progress: 0,
            paused: false,
            cancelled: false,
            totalChunks,
            sentChunks: 0,
            startedAt: Date.now()
        };

        uploadId = state.uploadId;

        this.activeUploads.set(uploadId, state);
        this._callCallback('onStart', file, state);
        this.trigger('upload:start', { file, state });

        try {
            for (let index = 0; index < totalChunks; index++) {
                if (state.cancelled) {
                    throw new Error('Upload canceled.');
                }

                while (state.paused) {
                    await this._sleep(250);
                    if (state.cancelled) throw new Error('Upload canceled.');
                }

                const start = index * this.options.chunkSize;
                const end = Math.min(file.size, start + this.options.chunkSize);
                const chunkBlob = file.slice(start, end);

                const formData = new FormData();
                formData.append(this.options.fieldNames.uploadId, uploadId);
                formData.append(this.options.fieldNames.chunkIndex, index);
                formData.append('total_chunks', totalChunks);
                formData.append('original_name', file.name);
                formData.append('file', chunkBlob, file.name);
                
                Object.entries(this.options.extraData || {}).forEach(([k, v]) => {
                    formData.append(k, v);
                });

                Object.entries(meta || {}).forEach(([k, v]) => {
                    formData.append(k, v);
                });

                const response = await this._ajax({
                    url: this.options.endpoints.chunk,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: $.extend(true, {}, this.options.requestHeaders, {
                        'Content-Range': `bytes ${start}-${end - 1}/${file.size}`
                    }),
                    xhr: () => {
                        const xhr = $.ajaxSettings.xhr();
                        if (xhr.upload) {
                            xhr.upload.onprogress = (evt) => {
                                if (!evt.lengthComputable) return;
                                const base = (index / totalChunks) * 100;
                                const part = (evt.loaded / evt.total) * (100 / totalChunks);
                                const progress = Math.min(99, Math.round(base + part));
                                state.progress = progress;
                                this.activeUploads.set(uploadId, state);
                                this._callCallback('onProgress', file, progress, state);
                                this.trigger('upload:progress', { file, progress, state, chunkIndex: index });
                            };
                        }
                        return xhr;
                    }
                });

                state.sentChunks++;
                state.progress = Math.round((state.sentChunks / totalChunks) * 100);
                this.activeUploads.set(uploadId, state);
                this._callCallback('onProgress', file, state.progress, state);
                this.trigger('upload:progress', { file, progress: state.progress, state, chunkIndex: index, response });
            }

            const finalizeResp = await this._postJSON(this.options.endpoints.finalize, {
                [this.options.fieldNames.uploadId]: uploadId
            });

            if (!finalizeResp || finalizeResp.ok === false) {
                throw new Error(finalizeResp?.error || 'The upload could not be completed.');
            }

            state.progress = 100;
            this.activeUploads.delete(uploadId);

            this._callCallback('onSuccess', file, finalizeResp, state);
            this._callCallback('onComplete', file, state);
            this.trigger('upload:success', { file, response: finalizeResp, state });
            this.trigger('upload:complete', { file, state });

            return this._ok({
                uploadId,
                file,
                response: finalizeResp,
                mode: 'chunked'
            });
        } catch (err) {
            this.activeUploads.delete(uploadId);
            const error = this._normalizeAjaxError(err);

            if (state.cancelled) {
                this._callCallback('onCancel', state);
                this.trigger('upload:cancel', { file, state });
            } else {
                this._callCallback('onError', file, error, state);
                this.trigger('upload:error', { file, error, state });
            }

            this._callCallback('onComplete', file, state);
            this.trigger('upload:complete', { file, state });

            return this._fail(file, error, { state });
        }
    }

    _buildChunkUrl(baseUrl, uploadId, chunkIndex) {
        const sep = baseUrl.includes('?') ? '&' : '?';
        return `${baseUrl}${sep}${encodeURIComponent(this.options.fieldNames.uploadId)}=${encodeURIComponent(uploadId)}&${encodeURIComponent(this.options.fieldNames.chunkIndex)}=${encodeURIComponent(chunkIndex)}`;
    }

    _normalizeAjaxError(err) {
        if (!err) return 'Unknown error.';
        if (typeof err === 'string') return err;
        if (err.error) return String(err.error);
        if (err.status === 'abort') return 'The request was aborted.';
        if (err.xhr && err.xhr.responseJSON && err.xhr.responseJSON.error) {
            return err.xhr.responseJSON.error;
        }
        if (err.xhr && err.xhr.responseText) {
            return err.xhr.responseText;
        }
        return 'An error occurred while sending the file.';
    }

    _sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}
