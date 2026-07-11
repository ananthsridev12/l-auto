<?php
// One-off generator for the Design Template picker's thumbnail gallery —
// run locally whenever render_design_templates() gains/changes a preset,
// commit the resulting PNGs. Not run per-request: pages/*.php just serve
// the static output from assets/img/template-thumbs/.
//
// Usage: php scripts/render_template_thumbnails.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';

$sample = [
    'title' => 'thumb', 'caption' => 'c', 'format' => 'single', 'series_label' => '',
    'template' => 1,
    'slides' => [[
        'slide_number' => 1,
        'headline' => 'Grow **Faster** With Better Content',
        'body' => 'A clear, confident message beats a cluttered one every time.',
        'points' => ['Consistent posting builds trust over time', 'Clear visuals hold attention longer', 'Strong CTAs turn views into replies'],
    ]],
];

$outDir = __DIR__ . '/../assets/img/template-thumbs';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Could not create {$outDir}\n");
    exit(1);
}

$tmpDir = sys_get_temp_dir() . '/design_template_thumbs_' . getmypid();

foreach (array_keys(render_design_templates()) as $id) {
    $sample['layout'] = $id;
    $slides = render_creative_to_slides($sample, $tmpDir, 'Your Name', null, 0);
    $full = $slides[0]['filepath'];

    $src = imagecreatefrompng($full);
    $thumb = imagecreatetruecolor(320, 320);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, 320, 320, imagesx($src), imagesy($src));
    imagepng($thumb, "{$outDir}/{$id}.png", 6);
    imagedestroy($src);
    imagedestroy($thumb);
    @unlink($full);

    echo "{$id}: OK\n";
}

@rmdir($tmpDir);
