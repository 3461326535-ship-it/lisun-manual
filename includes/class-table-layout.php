<?php
/**
 * LISUN 型号/编号表格布局处理。
 *
 * 所有至少两列、且首列不是横向合并的表格都会增加布局标记。脚本会根据
 * 表格的逻辑列位置计算首列最长内容的近似像素宽度，正确跳过 rowspan 续行；
 * CSS 使用固定表格布局让首列按内容分配宽度，其余列平均分配剩余空间。
 */

if (!class_exists('Lisun_Manual_Table_Layout')) {
    class Lisun_Manual_Table_Layout {
        const TABLE_CLASS = 'lisun-model-number-table';
        const TWO_COLUMN_CLASS = 'lisun-two-column-table';
        const AUTO_COLUMN_CLASS = 'lisun-auto-column-table';

        /**
         * 给正文中符合条件的 table 增加布局标记。
         *
         * @param string $content
         * @param int    $target_count
         * @param int    $changed_count
         * @return string
         */
        public static function apply_to_content($content, &$target_count = 0, &$changed_count = 0) {
            $target_count = 0;
            $changed_count = 0;

            $updated = preg_replace_callback(
                '/<table\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?<\/table\s*>/is',
                function ($match) use (&$target_count, &$changed_count) {
                    // 新导入的 DOCX 若已携带 Word 原始 tblGrid 列宽，保留 <colgroup>，
                    // 但仍然套用”首列按内容、其余吃剩余”规则，避免 Word 等宽分栏难看。
                    $is_word_grid = preg_match('/\bdata-lisun-source-layout\s*=\s*[“\']word-grid[“\']/i', $match[0]);

                    $column_count = 0;
                    $is_model_table = self::is_target_table($match[0], $column_count);
                    $is_auto_column_table = self::is_auto_column_table($match[0], $auto_column_count);
                    if (!$is_model_table && !$is_auto_column_table) {
                        return $match[0];
                    }
                    if (!$is_model_table) {
                        $column_count = $auto_column_count;
                    }

                    $target_count++;
                    if (!preg_match('/^\s*<table\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/is', $match[0], $opening_match)) {
                        return $match[0];
                    }

                    // 这里处理的是当前正在保存/推送的 HTML：中文页面按中文计算，
                    // 英文页面按英文计算，不会把中文原文的宽度带到翻译页面。
                    $first_width = self::get_first_column_width($match[0], $column_count);
                    $table_class = $is_model_table ? self::TABLE_CLASS : self::AUTO_COLUMN_CLASS;
                    $new_opening = self::decorate_opening_tag($opening_match[0], $column_count, $first_width, $table_class);
                    if (self::is_complex_table($match[0])) {
                        $new_opening = self::add_table_class($new_opening, 'lisun-complex-table');
                    }
                    // 给每个单元格写入它在逻辑表格中的列号。不能用 :first-child
                    // 判断列，因为 rowspan 的续行里，第二列单元格会成为 DOM 的第一个子节点。
                    $annotated_table = self::decorate_cell_columns($match[0]);
                    $new_table = $new_opening . substr($annotated_table, strlen($opening_match[0]));
                    if ($new_table === $match[0]) {
                        return $match[0];
                    }

                    $changed_count++;
                    return $new_table;
                },
                $content
            );

            return $updated === null ? $content : $updated;
        }

        /**
         * 将正文中的所有 table 宽度统一为 100%。
         *
         * 同时处理 table 的 style="width:..." 和 width="..." 属性，
         * 没有宽度属性时补充内联样式。内联样式可以让后台经典编辑器
         * 和前台页面使用同一宽度，不依赖前台主题 CSS 是否已加载。
         *
         * @param string $content
         * @param int    $table_count
         * @param int    $changed_count
         * @return string
         */
        public static function apply_table_widths($content, &$table_count = 0, &$changed_count = 0) {
            $table_count = 0;
            $changed_count = 0;

            $updated = preg_replace_callback(
                '/<table\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/is',
                function ($match) use (&$table_count, &$changed_count) {
                    $table_count++;
                    $tag = $match[0];
                    $changed = false;
                    $has_style = false;
                    $has_width_attr = false;

                    $tag = preg_replace_callback(
                        '/(\sstyle\s*=\s*)(["\'])(.*?)\2/is',
                        function ($style_match) use (&$has_style, &$changed) {
                            $has_style = true;
                            $style = $style_match[3];
                            $updated_style = preg_replace_callback(
                                '/(^|;)(\s*width\s*:\s*)([^;!]*?)(\s*!important)?(\s*)(;|$)/i',
                                function ($width_match) use (&$changed) {
                                    $changed = true;
                                    return $width_match[1] . $width_match[2] . '100%'
                                        . ($width_match[4] ?? '') . ($width_match[5] ?? '') . $width_match[6];
                                },
                                $style,
                                -1,
                                $width_count
                            );

                            if ($width_count === 0) {
                                $updated_style = $style
                                    . (substr($style, -1) === ';' || $style === '' ? '' : ';')
                                    . 'width:100%;';
                                $changed = true;
                            }

                            return $style_match[1] . $style_match[2] . $updated_style . $style_match[2];
                        },
                        $tag,
                        1
                    );

                    $tag = preg_replace_callback(
                        '/(\swidth\s*=\s*)(?:(["\x27])(.*?)\2|([^\s>]+))/i',
                        function ($width_attr_match) use (&$has_width_attr, &$changed) {
                            $has_width_attr = true;
                            $changed = true;
                            if (!empty($width_attr_match[2])) {
                                return $width_attr_match[1] . $width_attr_match[2] . '100%' . $width_attr_match[2];
                            }
                            return $width_attr_match[1] . '100%';
                        },
                        $tag,
                        1
                    );

                    if (!$has_style && !$has_width_attr) {
                        $tag = preg_replace('/>$/', ' style="width:100%;">', $tag, 1);
                        $changed = true;
                    }

                    if ($changed) {
                        $changed_count++;
                    }
                    return $tag;
                },
                $content
            );

            return $updated === null ? $content : $updated;
        }

        /**
         * 判断表格第一行第一列是否为 LISUN 型号或编号标签。
         *
         * @param string $table_html
         * @param int    $column_count
         * @return bool
         */
        public static function is_target_table($table_html, &$column_count = 0) {
            $column_count = 0;
            if (!preg_match('/<tr\b[^>]*>(.*?)<\/tr\s*>/is', $table_html, $row_match)) {
                return false;
            }

            preg_match_all(
                '/<(td|th)\b([^>]*)>.*?<\/\1\s*>/is',
                $row_match[1],
                $cell_matches,
                PREG_SET_ORDER
            );

            if (empty($cell_matches)) {
                return false;
            }

            $first_attrs = $cell_matches[0][2] ?? '';
            $first_colspan = self::get_span($first_attrs, 'colspan');
            if ($first_colspan !== 1) {
                return false;
            }

            foreach ($cell_matches as $cell) {
                $column_count += self::get_span($cell[2] ?? '', 'colspan');
            }

            $label_html = $cell_matches[0][0];
            $label_html = preg_replace('/^<[^>]+>|<\/[^>]+>$/', '', $label_html, 2);
            $label = self::normalize_label(self::plain_text($label_html));

            return self::is_model_or_number_label($label) && $column_count >= 1;
        }

        /**
         * 兼容旧调用：判断表格是否恰好为两列。
         * 新逻辑统一使用 is_auto_column_table()，支持所有两列以上的表格。
         *
         * @param string $table_html
         * @param int    $column_count
         * @return bool
         */
        public static function is_two_column_table($table_html, &$column_count = 0) {
            $is_auto = self::is_auto_column_table($table_html, $column_count);
            return $is_auto && $column_count === 2;
        }

        /**
         * 判断是否为需要自动计算列宽的表格。
         *
         * 不再限制为型号表或两列表格：只要表格中存在至少两列、且首列
         * 不是 colspan 合并单元格的行，就按“首列按内容、其余列平均”处理。
         * 这样 Level / Definition / By decision 等普通三列表格也会生效。
         *
         * @param string $table_html
         * @param int    $column_count
         * @return bool
         */
        public static function is_auto_column_table($table_html, &$column_count = 0) {
            $column_count = 0;
            preg_match_all('/<tr\b[^>]*>(.*?)<\/tr\s*>/is', $table_html, $rows);

            foreach ($rows[1] ?? [] as $row_html) {
                preg_match_all(
                    '/<(td|th)\b([^>]*)>.*?<\/\1\s*>/is',
                    $row_html,
                    $cell_matches,
                    PREG_SET_ORDER
                );
                if (empty($cell_matches) || self::get_span($cell_matches[0][2] ?? '', 'colspan') !== 1) {
                    continue;
                }

                $row_columns = 0;
                foreach ($cell_matches as $cell) {
                    $row_columns += self::get_span($cell[2] ?? '', 'colspan');
                }
                if ($row_columns >= 2) {
                    $column_count = max($column_count, $row_columns);
                }
            }

        return $column_count >= 2;
        }

        /**
         * 判断是否存在横向合并（colspan）。
         * rowspan 只是纵向合并，不破坏列宽分配，不需要标记为 complex。
         * 只有 colspan 会让某些单元格跨越多列，此时固定列宽会出错。
         */
        private static function is_complex_table($table_html) {
            preg_match_all(
                '/<(td|th)\b([^>]*)>/is',
                $table_html,
                $cells,
                PREG_SET_ORDER
            );

            foreach ($cells as $cell) {
                $attrs = $cell[2] ?? '';
                if (self::get_span($attrs, 'colspan') > 1) {
                    return true;
                }
            }

            return false;
        }

        /** 给 table 开始标签增加 class，保留其余属性。 */
        private static function add_table_class($opening_tag, $class_name) {
            if (preg_match('/(\bclass\s*=\s*)("|\')([^"\']*)\2/i', $opening_tag, $match, PREG_OFFSET_CAPTURE)) {
                $class_value = $match[3][0];
                if (preg_match('/(?:^|\s)' . preg_quote($class_name, '/') . '(?:\s|$)/', $class_value)) {
                    return $opening_tag;
                }
                $quote = $match[2][0];
                $new_attr = $match[1][0] . $quote . trim($class_value . ' ' . $class_name) . $quote;
                return substr_replace($opening_tag, $new_attr, $match[0][1], strlen($match[0][0]));
            }

            return preg_replace('/>$/', ' class="' . esc_attr($class_name) . '">', $opening_tag, 1);
        }

        /**
         * 给 table 开始标签添加 class 和列数数据属性，保留其它属性原样。
         *
         * @param string $opening_tag
         * @param int    $column_count
         * @param int    $first_width
         * @param string $table_class
         * @return string
         */
        public static function decorate_opening_tag($opening_tag, $column_count, $first_width = 0, $table_class = self::TABLE_CLASS) {
            $expected_columns = max(1, (int) $column_count);
            $first_width = max(60, (int) $first_width);

            $has_class = preg_match('/(\bclass\s*=\s*)("|\')([^"\']*)\2/i', $opening_tag, $class_match, PREG_OFFSET_CAPTURE);
            if ($has_class) {
                $class_value = $class_match[3][0];
                if (!preg_match('/(?:^|\s)' . preg_quote($table_class, '/') . '(?:\s|$)/', $class_value)) {
                    $new_class_value = trim($class_value . ' ' . $table_class);
                    $quote = $class_match[2][0];
                    $full_attr = $class_match[0][0];
                    $new_attr = $class_match[1][0] . $quote . $new_class_value . $quote;
                    $opening_tag = substr_replace($opening_tag, $new_attr, $class_match[0][1], strlen($full_attr));
                }
            } else {
                $opening_tag = preg_replace('/>$/', ' class="' . $table_class . '">', $opening_tag, 1);
            }

            if (preg_match('/\bdata-lisun-table-columns\s*=\s*("|\')([^"\']*)\1/i', $opening_tag)) {
                $opening_tag = preg_replace(
                    '/(\bdata-lisun-table-columns\s*=\s*)("|\')[^"\']*\2/i',
                    '${1}${2}' . $expected_columns . '${2}',
                    $opening_tag,
                    1
                );
            } else {
                $opening_tag = preg_replace(
                    '/>$/',
                    ' data-lisun-table-columns="' . $expected_columns . '">',
                    $opening_tag,
                    1
                );
            }

            $rest_count = max(1, $expected_columns - 1);
            $rest_percent = 100 / $rest_count;
            $rest_percent_text = rtrim(rtrim(number_format($rest_percent, 4, '.', ''), '0'), '.');
            $rest_px = $first_width / $rest_count;
            $rest_px_text = rtrim(rtrim(number_format($rest_px, 2, '.', ''), '0'), '.');
            $min_width = $first_width + ($rest_count * 140);
            $layout_style = '--lisun-first-column-width:' . $first_width . 'px;'
                . '--lisun-rest-column-width:calc(' . $rest_percent_text . '% - ' . $rest_px_text . 'px);'
                . '--lisun-table-rest-count:' . $rest_count . ';'
                . '--lisun-table-min-width:' . $min_width . 'px;';
            $opening_tag = self::set_layout_style($opening_tag, $layout_style);

            return $opening_tag;
        }

        /**
         * 估算首列最长内容的显示宽度。
         *
         * 以当前 HTML 中的文字计算，并设置上限：两列表格首列最多约占 45%，
         * 多列表格首列最多约占 35%。超过上限的文字正常换行，避免英文长词/长句
         * 抢走后面列的空间。其余列由 CSS 平均分配剩余宽度。
         */
        private static function get_first_column_width($table_html, $column_count = 2) {
            $max_units = 0;
            $rowspans = [];
            preg_match_all('/<tr\b[^>]*>(.*?)<\/tr\s*>/is', $table_html, $rows);

            foreach ($rows[1] ?? [] as $row_html) {
                preg_match_all(
                    '/<(td|th)\b([^>]*)>(.*?)<\/\1\s*>/is',
                    $row_html,
                    $cells,
                    PREG_SET_ORDER
                );
                $column = 0;

                foreach ($cells as $cell) {
                    while (($rowspans[$column] ?? 0) > 0) {
                        $column++;
                    }

                    $attrs = $cell[2] ?? '';
                    $colspan = self::get_span($attrs, 'colspan');
                    $rowspan = self::get_span($attrs, 'rowspan');

                    // 只有真实位于逻辑第 0 列、且没有横向跨列的单元格，
                    // 才能参与首列宽度计算。rowspan 续行的单元格会从第 1 列开始。
                    if ($column === 0 && $colspan === 1) {
                        $text = self::plain_text($cell[3] ?? '');
                        $units = 0;
                        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
                        foreach ($characters ?: [] as $character) {
                            if (preg_match('/[\x{2e80}-\x{9fff}\x{3000}-\x{303f}\x{ff00}-\x{ffef}]/u', $character)) {
                                $units += 1.0;
                            } elseif ($character === ' ') {
                                $units += 0.35;
                            } else {
                                $units += 0.56;
                            }
                        }
                        $max_units = max($max_units, $units);
                    }

                    for ($offset = 0; $offset < $colspan; $offset++) {
                        $occupied_column = $column + $offset;
                        if ($rowspan > 1) {
                            $rowspans[$occupied_column] = max($rowspans[$occupied_column] ?? 0, $rowspan);
                        }
                    }
                    $column += $colspan;
                }

                foreach ($rowspans as $occupied_column => $remaining_rows) {
                    if ($remaining_rows > 0) {
                        $rowspans[$occupied_column] = $remaining_rows - 1;
                    }
                }
            }

            $natural_width = (int) ceil($max_units * 15 + 28);
            $natural_width = max(72, $natural_width);

            // 950px 是正文内容区的最大宽度；这里的上限只防止首列失控，
            // 不是强制把正常的短标签放大到固定比例。
            $max_ratio = ((int) $column_count <= 2) ? 0.45 : 0.35;
            $max_width = (int) floor(950 * $max_ratio);

            // 短标签不再强制撑到 240px；两列表格通常 120px 已足够容纳首列文字。
            $minimum_width = ((int) $column_count <= 2) ? 120 : 140;
            return min($natural_width, max($minimum_width, $max_width));
        }

        /**
         * 为单元格添加逻辑列号，正确处理 rowspan/colspan。
         *
         * @param string $table_html
         * @return string
         */
        private static function decorate_cell_columns($table_html) {
            $rowspans = [];
            $updated = preg_replace_callback(
                '/<tr\b[^>]*>.*?<\/tr\s*>/is',
                function ($row_match) use (&$rowspans) {
                    $row_html = $row_match[0];
                    $column = 0;
                    $updated_row = preg_replace_callback(
                        '/<(td|th)\b([^>]*)>(.*?)<\/\1\s*>/is',
                        function ($cell_match) use (&$rowspans, &$column) {
                            while (($rowspans[$column] ?? 0) > 0) {
                                $column++;
                            }

                            $attrs = $cell_match[2] ?? '';
                            $colspan = self::get_span($attrs, 'colspan');
                            $rowspan = self::get_span($attrs, 'rowspan');

                            $opening_tag = '<' . $cell_match[1] . $attrs . '>';
                            $opening_tag = self::set_data_attribute(
                                $opening_tag,
                                'data-lisun-column',
                                (string) $column
                            );

                            for ($offset = 0; $offset < $colspan; $offset++) {
                                $occupied_column = $column + $offset;
                                if ($rowspan > 1) {
                                    $rowspans[$occupied_column] = max($rowspans[$occupied_column] ?? 0, $rowspan);
                                }
                            }
                            $replacement = $opening_tag . substr($cell_match[0], strlen('<' . $cell_match[1] . $attrs . '>'));
                            $column += $colspan;
                            return $replacement;
                        },
                        $row_html
                    );

                    foreach ($rowspans as $occupied_column => $remaining_rows) {
                        if ($remaining_rows > 0) {
                            $rowspans[$occupied_column] = $remaining_rows - 1;
                        }
                    }

                    return $updated_row === null ? $row_html : $updated_row;
                },
                $table_html
            );

            return $updated === null ? $table_html : $updated;
        }

        private static function set_data_attribute($opening_tag, $name, $value) {
            $pattern = '/\b' . preg_quote($name, '/') . '\s*=\s*("|\')[^"\']*\1/i';
            if (preg_match($pattern, $opening_tag)) {
                return preg_replace(
                    $pattern,
                    $name . '="' . esc_attr($value) . '"',
                    $opening_tag,
                    1
                );
            }

            return preg_replace('/>$/', ' ' . $name . '="' . esc_attr($value) . '">', $opening_tag, 1);
        }

        private static function set_layout_style($opening_tag, $layout_style) {
            if (preg_match('/(\bstyle\s*=\s*)("|\')(.*?)\2/is', $opening_tag, $style_match, PREG_OFFSET_CAPTURE)) {
                $style_value = rtrim($style_match[3][0]);
                if ($style_value !== '' && substr($style_value, -1) !== ';') {
                    $style_value .= ';';
                }
                foreach (['--lisun-first-column-width', '--lisun-rest-column-width', '--lisun-table-rest-count', '--lisun-table-min-width'] as $property) {
                    $style_value = preg_replace(
                        '/\s*' . preg_quote($property, '/') . '\s*:[^;]*;?/i',
                        '',
                        $style_value
                    );
                }
                $style_value = preg_replace('/;{2,}/', ';', $style_value);
                $style_value = trim($style_value);
                if ($style_value !== '' && substr($style_value, -1) !== ';') {
                    $style_value .= ';';
                }
                $new_attr = $style_match[1][0] . $style_match[2][0] . $style_value . $layout_style . $style_match[2][0];
                return substr_replace($opening_tag, $new_attr, $style_match[0][1], strlen($style_match[0][0]));
            }

            return preg_replace('/>$/', ' style="' . $layout_style . '">', $opening_tag, 1);
        }

        private static function get_span($attrs, $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(?:"([0-9]+)"|\'([0-9]+)\'|([0-9]+))/i', $attrs, $match)) {
                return max(1, (int) ($match[1] ?: ($match[2] ?: $match[3])));
            }
            return 1;
        }

        private static function plain_text($html) {
            $html = preg_replace('/<br\s*\/?>/i', ' ', $html);
            $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim(preg_replace('/\s+/u', ' ', $html));
        }

        private static function normalize_label($label) {
            $label = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
            return preg_replace('/[\s\x{00a0}:：\/_\-.]+/u', '', $label);
        }

        private static function is_model_or_number_label($label) {
            return (bool) preg_match(
                '/^(?:lisun)?(?:型号|编号|model|modelno|modelnumber|productmodel|productno|number|no|itemno|partno)$/u',
                $label
            );
        }
    }
}
