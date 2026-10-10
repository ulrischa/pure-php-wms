<?php

declare(strict_types=1);

namespace PureWms;

/** One rule view shared by rendering, legend selection and SLD export. */
final class StyleRules
{
    public static function entries(array $style): array
    {
        $entries = [];
        if (isset($style['_sld_groups'])) {
            foreach ($style['_sld_groups'] as $g => $group) {
                foreach ($group as $r => $rule) {
                    $entries[] = $rule + ['id' => "$g:$r", 'name' => "rule_$g" . "_$r", 'group' => $g];
                }
            }
            return $entries;
        }
        $parts = [['name' => 'default', 'title' => $style['title'] ?? 'Default', 'part' => $style]];
        foreach ($style['rules'] ?? [] as $i => $rule) {
            $parts[] = ['name' => 'rule_' . ($i + 1), 'title' => (string)$rule['equals'], 'part' => array_replace($style, $rule['symbolizer'])];
        }
        foreach ($parts as $i => $part) {
            $entries[] = ['id' => (string)$i, 'name' => $part['name'], 'title' => $part['title'], 'symbols' => [$part['part']],
                'min' => $part['part']['min_scale'] ?? 0, 'max' => $part['part']['max_scale'] ?? 1e12, 'group' => 0, 'else' => false, 'filter' => null];
        }
        return $entries;
    }

    public static function matching(array $style, array $properties, float $scale): array
    {
        $entries = self::entries($style);
        if (!isset($style['_sld_groups'])) {
            $selected = 0;
            foreach ($style['rules'] ?? [] as $i => $rule) {
                if (isset($properties[$rule['field']]) && is_scalar($properties[$rule['field']]) && (string)$properties[$rule['field']] === (string)$rule['equals']) {
                    $selected = $i + 1;
                    break;
                }
            }
            $rule = $entries[$selected];
            return $scale >= $rule['min'] && $scale < $rule['max'] ? [$rule] : [];
        }
        $matchedGroups = [];
        $result = [];
        foreach ($entries as $rule) {
            if (!$rule['else'] && $scale >= $rule['min'] && $scale < $rule['max'] && Sld::matches($rule['filter'], $properties)) {
                $matchedGroups[$rule['group']] = true;
                $result[$rule['id']] = $rule;
            }
        }
        foreach ($entries as $rule) {
            if ($rule['else'] && !isset($matchedGroups[$rule['group']]) && $scale >= $rule['min'] && $scale < $rule['max']) {
                $result[$rule['id']] = $rule;
            }
        }
        return array_values(array_filter($entries, static fn ($rule) => isset($result[$rule['id']])));
    }
}
