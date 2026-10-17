/**
 * Busca digitável para um <select> de custos: <input data-busca-select="id_do_select">.
 * Filtra as opções já renderizadas pelo servidor (trecho do nome, sem diferenciar maiúsculas nem acentos).
 * O <select> continua sendo o campo enviado no formulário.
 */
(function () {
    function normalizar(texto) {
        return (texto || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    function iniciar(campo) {
        var select = document.getElementById(campo.getAttribute('data-busca-select'));
        if (!select) return;
        var status = document.getElementById(campo.getAttribute('data-busca-status') || '');
        var placeholder = null;
        var opcoes = [];
        Array.prototype.slice.call(select.options).forEach(function (opt) {
            if (opt.value === '') {
                placeholder = opt;
                return;
            }
            opcoes.push({ opt: opt, chave: normalizar(opt.getAttribute('data-nome') || opt.textContent) });
        });
        var textoPlaceholder = placeholder ? placeholder.textContent : '';

        function filtrar() {
            var termos = normalizar(campo.value).split(/\s+/).filter(Boolean);
            var anterior = select.value;
            var encontrados = opcoes.filter(function (o) {
                return termos.every(function (t) { return o.chave.indexOf(t) >= 0; });
            });

            while (select.firstChild) select.removeChild(select.firstChild);
            if (placeholder) {
                placeholder.textContent = termos.length && !encontrados.length ? 'Nenhum custo encontrado' : textoPlaceholder;
                select.appendChild(placeholder);
            }
            encontrados.forEach(function (o) { select.appendChild(o.opt); });

            if (termos.length) {
                select.size = Math.max(2, Math.min(encontrados.length + 1, 8));
            } else {
                select.removeAttribute('size');
            }

            var manteve = encontrados.some(function (o) { return o.opt.value === anterior; });
            select.value = manteve ? anterior : '';
            if (status) {
                status.textContent = termos.length
                    ? encontrados.length + ' de ' + opcoes.length + ' custos encontrados'
                    : opcoes.length + ' custos disponíveis';
            }
            if (select.value !== anterior) {
                select.dispatchEvent(new Event('change'));
            }
            return encontrados;
        }

        campo.addEventListener('input', filtrar);
        campo.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                var encontrados = filtrar();
                if (encontrados.length && select.value === '') {
                    select.value = encontrados[0].opt.value;
                    select.dispatchEvent(new Event('change'));
                }
                select.focus();
            } else if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                select.focus();
            }
        });
        filtrar();
    }

    Array.prototype.slice.call(document.querySelectorAll('input[data-busca-select]')).forEach(iniciar);
})();
