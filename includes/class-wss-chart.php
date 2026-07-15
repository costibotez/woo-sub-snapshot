<?php
/**
 * Renders a self-contained inline SVG line chart. No external assets, so it
 * works under strict CSP and never loads a script from a CDN.
 */

if (!defined('ABSPATH')) exit;

class WSS_Chart {

    /**
     * @param array $labels  X-axis labels (e.g. months).
     * @param array $series  List of ['label'=>string,'color'=>hex,'data'=>float[]].
     * @param array $args    Optional 'width','height','title'.
     */
    public static function render_line($labels, $series, $args = array()) {
        $width  = isset($args['width']) ? (int) $args['width'] : 760;
        $height = isset($args['height']) ? (int) $args['height'] : 300;
        $title  = isset($args['title']) ? $args['title'] : '';

        $pad_l = 44;
        $pad_r = 12;
        $pad_t = 16;
        $pad_b = 46;
        $plot_w = $width - $pad_l - $pad_r;
        $plot_h = $height - $pad_t - $pad_b;

        $count = count($labels);
        if ($count === 0) {
            return '';
        }

        // Y scale across all series.
        $max = 0;
        foreach ($series as $s) {
            foreach ($s['data'] as $v) {
                $max = max($max, (float) $v);
            }
        }
        if ($max <= 0) {
            $max = 1;
        }
        $max = self::nice_ceiling($max);

        $x_at = function ($i) use ($pad_l, $plot_w, $count) {
            if ($count === 1) {
                return $pad_l + $plot_w / 2;
            }
            return $pad_l + ($plot_w * $i / ($count - 1));
        };
        $y_at = function ($v) use ($pad_t, $plot_h, $max) {
            return $pad_t + $plot_h - ($plot_h * ((float) $v / $max));
        };

        $svg  = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="' . $height . '" role="img" style="max-width:' . $width . 'px;font-family:sans-serif;">';
        if ($title !== '') {
            $svg .= '<title>' . esc_html($title) . '</title>';
        }

        // Horizontal gridlines + Y labels.
        $ticks = 4;
        for ($t = 0; $t <= $ticks; $t++) {
            $val = $max * $t / $ticks;
            $y   = $y_at($val);
            $svg .= '<line x1="' . $pad_l . '" y1="' . round($y, 1) . '" x2="' . ($width - $pad_r) . '" y2="' . round($y, 1) . '" stroke="#e0e0e0" stroke-width="1" />';
            $svg .= '<text x="' . ($pad_l - 6) . '" y="' . round($y + 3, 1) . '" text-anchor="end" font-size="10" fill="#767676">' . esc_html(self::fmt($val)) . '</text>';
        }

        // X labels (thinned so they don't overlap).
        $step = (int) max(1, ceil($count / 12));
        for ($i = 0; $i < $count; $i++) {
            if ($i % $step !== 0 && $i !== $count - 1) {
                continue;
            }
            $x = $x_at($i);
            $svg .= '<text x="' . round($x, 1) . '" y="' . ($height - $pad_b + 16) . '" text-anchor="middle" font-size="10" fill="#767676">' . esc_html($labels[$i]) . '</text>';
        }

        // Series lines + points.
        foreach ($series as $s) {
            $color = isset($s['color']) ? $s['color'] : '#2271b1';
            $points = array();
            foreach ($s['data'] as $i => $v) {
                $points[] = round($x_at($i), 1) . ',' . round($y_at($v), 1);
            }
            if (count($points) > 1) {
                $svg .= '<polyline fill="none" stroke="' . esc_attr($color) . '" stroke-width="2" points="' . esc_attr(implode(' ', $points)) . '" />';
            }
            foreach ($s['data'] as $i => $v) {
                $svg .= '<circle cx="' . round($x_at($i), 1) . '" cy="' . round($y_at($v), 1) . '" r="2.5" fill="' . esc_attr($color) . '"><title>' . esc_html($s['label'] . ' — ' . $labels[$i] . ': ' . self::fmt($v)) . '</title></circle>';
            }
        }

        $svg .= '</svg>';

        // Legend.
        $legend = '<div style="margin:6px 0 0;font-size:12px;">';
        foreach ($series as $s) {
            $color = isset($s['color']) ? $s['color'] : '#2271b1';
            $legend .= '<span style="display:inline-block;margin-right:14px;">'
                . '<span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' . esc_attr($color) . ';margin-right:5px;"></span>'
                . esc_html($s['label']) . '</span>';
        }
        $legend .= '</div>';

        return '<div class="wss-chart">' . $svg . $legend . '</div>';
    }

    private static function nice_ceiling($max) {
        $pow  = pow(10, floor(log10($max)));
        $step = $pow / 2;
        return ceil($max / $step) * $step;
    }

    private static function fmt($v) {
        if ($v >= 1000) {
            return round($v / 1000, 1) . 'k';
        }
        return (string) (round($v * 10) / 10);
    }
}
