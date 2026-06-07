<p>Only 1 file at a time and (image.jpg, file.pdf) file name</p>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="../resources/js/JQueryFileUploader.js"></script>
<script>
    const uploader = new JQueryFileUploader({
    container: '#uploadBox',
    endpoints: {
        start: window.location.href + '../atpi/uploadMulti/?action=start',
        chunk: window.location.href + '../atpi/uploadMulti/?action=chunk',
        finalize: window.location.href + '../atpi/uploadMulti/?action=finalize',
        pause: window.location.href + '../atpi/uploadMulti/?action=pause',
        resume: window.location.href + '../atpi/uploadMulti/?action=resume',
        cancel: window.location.href + '../atpi/uploadMulti/?action=cancel',
        upload: window.location.href + '../atpi/uploadMulti/?action=upload'
    },
    multiple: false,
    maxFiles: 1,
    chunked: false,
    chunkSize: 100 * 1024,
    maxFileSize: 20 * 1024 * 1024,
    allowedExtensions: ['jpg', 'jpeg', 'png', 'pdf'],
    deniedExtensions: ['php', 'js', 'exe'],
    allowedNames: ['image.jpg', 'file.pdf'],
    blockedNames: ['virus.php'],
    callbacks: {
        beforeUpload: (file) => {
            console.log('Before start:', file.name);
        },
        beforeStart: (file) => {
            console.log('Shipping soon:', file.name);
        },
        onStart: (file, state) => {
            console.log('Start:', file.name, state.uploadId, file, state);
        },
        onProgress: (file, progress) => {
            console.log('Progress:', file.name, progress + '%');
        },
        onSuccess: (file, response) => {
            console.log('Success:', file.name, response);
        },
        onError: (file, error) => {
            console.error('Error:', file.name, error);
        },
        onComplete: (file) => {
            console.log('Complete:', file.name);
        },
        onRejected: (file, reasons) => {
            console.warn('Rejected:', file.name, reasons);
        }
    }
});

$(document).on('change', '#files', async function () {
    console.log(this);
   
    uploader.on('upload:start', (e, data) => console.log('event start', data));
    uploader.on('upload:progress', (e, data) => console.log('event progress', data));
    uploader.on('upload:success', (e, data) => console.log('event success', data));
    uploader.on('upload:error', (e, data) => console.log('event error', data));
    uploader.on('upload:complete', (e, data) => console.log('event complete', data));
    uploader.on('upload:pause', (e, data) => console.log('event pause', data));
    uploader.on('upload:resume', (e, data) => console.log('event resume', data));
    uploader.on('upload:cancel', (e, data) => console.log('event cancel', data));
    uploader.on('upload:rejected', (e, data) => console.log('event rejected', data));

    const result = await uploader.uploadFromInput(this, {
        title: 'My Title'
    });

    if (!result.ok) {
        console.error(result.error);
    } else {
        console.log(result.data);
    }
});
</script>
<section id="uploadBox">
    <input type="file" name="files" id="files">
</section>
