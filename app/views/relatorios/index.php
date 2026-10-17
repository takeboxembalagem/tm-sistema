<p class="hint" style="margin:0 0 16px">Informações e análises do sistema T&M</p>

<?php foreach ($grupos as $grupo): ?>
    <section style="margin-bottom:24px">
        <h2 style="margin:0 0 12px;font-size:1.05rem"><?= e($grupo['titulo']) ?></h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">
            <?php foreach ($grupo['relatorios'] as $rel): ?>
                <article class="card" style="display:flex;flex-direction:column;gap:8px">
                    <h3 style="margin:0;font-size:0.95rem"><?= e($rel['titulo']) ?></h3>
                    <p class="hint" style="margin:0;flex:1"><?= e($rel['descricao']) ?></p>
                    <div>
                        <?php if (!empty($rel['rota'])): ?>
                            <a class="btn-primary" href="<?= e(url($rel['rota'])) ?>">Abrir</a>
                        <?php else: ?>
                            <span class="badge badge-off">Em desenvolvimento</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
