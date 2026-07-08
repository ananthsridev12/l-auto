  </main>

</div>
<?php if (!empty($pageScripts)) foreach ($pageScripts as $__script): ?>
<script src="<?= h(app_path('assets/js/' . $__script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
