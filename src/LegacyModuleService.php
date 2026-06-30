<?php

namespace Stock2;

use RuntimeException;

final class LegacyModuleService
{
    /** @var array<int, array<string, mixed>> */
    private array $groups;

    /** @var array<string, array<string, mixed>> */
    private array $modules = [];

    /** @param array<int, array<string, mixed>> $groups */
    public function __construct(array $groups)
    {
        $normalizedGroups = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            if (isset($group['group_title'])) {
                $group['group_title'] = $this->normalizeLabel((string)$group['group_title']);
            }

            $items = $group['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }

            $normalizedItems = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (isset($item['title'])) {
                    $item['title'] = $this->normalizeLabel((string)$item['title']);
                }
                if (isset($item['message'])) {
                    $item['message'] = $this->normalizeLabel((string)$item['message']);
                }

                if (!isset($item['key'])) {
                    continue;
                }

                $normalizedItems[] = $item;
                $this->modules[(string)$item['key']] = $item;
            }

            if (!empty($normalizedItems)) {
                $group['items'] = $normalizedItems;
                $normalizedGroups[] = $group;
            }
        }

        $this->groups = $normalizedGroups;
    }

    /** @return array<int, array<string, mixed>> */
    public function allGroups(): array
    {
        return $this->groups;
    }

    /** @return array<int, array<string, mixed>> */
    public function allModules(): array
    {
        $modules = [];
        foreach ($this->groups as $group) {
            $items = $group['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (is_array($item) && isset($item['key'])) {
                    $modules[] = $item;
                }
            }
        }

        return $modules;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return $this->modules[$key] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function groupsForAuth(AuthService $auth): array
    {
        $result = [];

        foreach ($this->groups as $group) {
            $items = $group['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }

            $filteredItems = [];
            foreach ($items as $item) {
                $formId = (int)($item['form_id'] ?? 0);
                $moduleKey = (string)($item['key'] ?? '');
                if ($auth->hasModulePermission($moduleKey, $formId)) {
                    $filteredItems[] = $item;
                }
            }

            if (!empty($filteredItems)) {
                $copy = $group;
                $copy['items'] = $filteredItems;
                $result[] = $copy;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $module */
    public function validateModule(array $module, SchemaService $schema): void
    {
        $mode = (string)($module['mode'] ?? 'single');

        if ($mode === 'placeholder') {
            return;
        }

        $mainTable = (string)($module['main_table'] ?? '');
        if ($mainTable === '') {
            throw new RuntimeException('Module main_table is required');
        }

        $schema->normalizeTable($mainTable);

        if ($mode === 'master_detail') {
            $detailTable = (string)($module['detail_table'] ?? '');
            if ($detailTable === '') {
                throw new RuntimeException('Module detail_table is required');
            }

            $schema->normalizeTable($detailTable);

            $sourceColumn = (string)($module['detail_source_column'] ?? '');
            $targetColumn = (string)($module['detail_target_column'] ?? '');
            if ($sourceColumn === '' || $targetColumn === '') {
                throw new RuntimeException('Module detail link columns are required');
            }
        }
    }

    private function normalizeLabel(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        if (preg_match('/Ã|Â|à¸|à¹|àº|à»/u', $text) !== 1) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
            if (is_string($converted) && $converted !== '' && strpos($converted, '?') === false) {
                return $converted;
            }
        }

        $converted2 = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
        if (is_string($converted2) && $converted2 !== '') {
            return $converted2;
        }

        return $text;
    }
}
