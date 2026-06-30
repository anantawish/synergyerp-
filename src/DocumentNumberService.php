<?php

namespace Stock2;

use PDO;
use RuntimeException;

final class DocumentNumberService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string, mixed> */
    public function next(string $billName): array
    {
        $billName = trim($billName);
        if ($billName === '') {
            throw new RuntimeException('billName is required');
        }

        $sql = 'SELECT pk, billname, bill_id, bill_head, bill_limit FROM billid WHERE billname = :billname LIMIT 1 FOR UPDATE';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['billname' => $billName]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('billid setting not found for: ' . $billName);
        }

        $pk = (int)$row['pk'];
        $current = (int)($row['bill_id'] ?? 0);
        $next = $current + 1;

        $headRaw = (string)($row['bill_head'] ?? '');
        $head = $this->sanitizeHead($headRaw, $billName);

        $limit = (int)($row['bill_limit'] ?? 5);
        if ($limit <= 0) {
            $limit = 5;
        }

        $running = str_pad((string)$next, $limit, '0', STR_PAD_LEFT);
        $code = $head . $running;

        $update = $this->pdo->prepare('UPDATE billid SET bill_id = :bill_id WHERE pk = :pk');
        $update->execute([
            'bill_id' => $next,
            'pk' => $pk,
        ]);

        return [
            'bill_name' => $billName,
            'sequence' => $next,
            'code' => $code,
            'head' => $head,
            'limit' => $limit,
        ];
    }

    private function sanitizeHead(string $head, string $billName): string
    {
        $head = trim($head);
        if ($head !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $head)) {
            return $head;
        }

        $head = preg_replace('/[^A-Za-z0-9]/', '', $head) ?? '';
        if ($head !== '') {
            return $head;
        }

        $billName = preg_replace('/[^A-Za-z0-9]/', '', $billName) ?? '';
        if ($billName === '') {
            return 'DOC';
        }

        return strtoupper(substr($billName, 0, 3));
    }
}