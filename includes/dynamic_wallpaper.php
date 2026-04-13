<?php

function wallos_get_dynamic_wallpaper_sources()
{
    return [
        [
            'src' => 'https://b2.akz.moe/awesome-pictures/ffv76cb_x264.mp4',
            'poster' => 'https://b2.akz.moe/awesome-pictures/ffv76cb_frame_1.webp',
        ],
        [
            'src' => 'https://b2.akz.moe/awesome-pictures/jfhcdfd5_x264_1080p_crf24.mp4',
            'poster' => 'https://b2.akz.moe/awesome-pictures/jfhcdfd5_frame_1.webp',
        ],
        [
            'src' => 'https://b2.akz.moe/awesome-pictures/蓝莓雪糕/1080p_60fps_crf25_x265_an.mp4',
            'poster' => 'https://b2.akz.moe/awesome-pictures/蓝莓雪糕/poster.webp',
        ],
        [
            'src' => 'https://b2.akz.moe/awesome-pictures/snare/7jmgdh.mp4',
            'poster' => 'https://b2.akz.moe/awesome-pictures/snare/poster.webp',
        ],
    ];
}

function wallos_render_dynamic_wallpaper()
{
    ?>
    <div class="wallos-dynamic-wallpaper" aria-hidden="true">
        <video class="wallos-dynamic-wallpaper-video" muted loop playsinline preload="none"></video>
        <div class="wallos-dynamic-wallpaper-overlay"></div>
    </div>
    <?php
}
