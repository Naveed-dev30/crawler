<script>
    window.showAppToast = function (title, message, color) {
        let container = document.getElementById('app-toasts');
        if (!container) {
            container = document.createElement('div');
            container.id = 'app-toasts';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1090';
            document.body.appendChild(container);
        }
        const el = document.createElement('div');
        el.className = 'toast bg-white border-0 shadow-lg rounded-3 overflow-hidden';
        el.setAttribute('role', 'alert');
        el.style.borderLeft = '4px solid ' + color;
        el.style.minWidth = '320px';
        el.innerHTML =
            '<div class="d-flex align-items-center p-3">' +
            '<span class="badge rounded-circle p-2 me-3 lh-1" style="color:' + color + ';background:' + color + '20">' +
            '<i class="bx bx-check bx-sm"></i></span>' +
            '<div class="me-3"><div class="fw-semibold text-body"></div>' +
            '<small class="text-muted"></small></div>' +
            '<button type="button" class="btn-close ms-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
        el.querySelector('.fw-semibold').textContent = title;
        el.querySelector('small').textContent = message;
        container.appendChild(el);
        el.addEventListener('hidden.bs.toast', () => el.remove());
        new bootstrap.Toast(el, { delay: 3000 }).show();
    };
</script>
