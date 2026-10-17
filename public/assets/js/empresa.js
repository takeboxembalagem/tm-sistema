(function () {
    function copiarFallback(texto) {
        var area = document.createElement('textarea');
        area.value = texto;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(area);
        return ok;
    }

    function copiar(texto) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(texto).then(function () { return true; }, function () { return copiarFallback(texto); });
        }
        return Promise.resolve(copiarFallback(texto));
    }

    function avisar(botao, ok) {
        var msg = ok ? (botao.getAttribute('data-copy-msg') || 'Copiado.') : 'Não foi possível copiar. Selecione o texto e copie manualmente.';
        var status = document.getElementById('copy-status');
        if (status) {
            status.textContent = msg;
            status.hidden = false;
            clearTimeout(status._timer);
            status._timer = setTimeout(function () { status.hidden = true; }, 3000);
        }
        if (ok && !botao.hasAttribute('data-copy-keep-label')) {
            var original = botao.getAttribute('data-label') || botao.textContent;
            botao.setAttribute('data-label', original);
            botao.textContent = 'Copiado!';
            setTimeout(function () { botao.textContent = original; }, 1500);
        }
    }

    document.addEventListener('click', function (ev) {
        var botao = ev.target.closest('[data-copy], [data-copy-target]');
        if (!botao) {
            return;
        }
        ev.preventDefault();
        var texto = botao.getAttribute('data-copy');
        if (texto === null) {
            var alvo = document.querySelector(botao.getAttribute('data-copy-target'));
            texto = alvo ? (alvo.value !== undefined ? alvo.value : alvo.textContent) : '';
        }
        if (!texto) {
            return;
        }
        copiar(texto).then(function (ok) { avisar(botao, ok); });
    });

    document.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-print]')) {
            ev.preventDefault();
            window.print();
        }
    });
})();
