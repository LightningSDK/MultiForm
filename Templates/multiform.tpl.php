<?php if (!empty($form['header'])): ?>
    <h2><?= $form['header']; ?></h2>
<?php endif; ?>
<?php if (!empty($form['subheader'])): ?>
    <h3><?= $form['subheader']; ?></h3>
<?php endif; ?>
<div class="form panel frame">
    <?= \Lightning\View\Form::render($form); ?>
</div>
<style>
</style>
