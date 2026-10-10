<?php
final class AssetEquipmentService {
    private const MODULE = 'Asset & Equipment Issuance';
    private const EXCLUDED_ASSET_SQL = "(a.asset_tag = 'AST-0003' AND a.name = 'Network Printer' AND a.serial_number = 'SN-GSMS-0003')";
    private const ACTIVE_ISSUANCE_STATUSES = ['Issued', 'Overdue', 'Not Returned'];

    public function __construct(private PDO $pdo, private AuditService $audit) {}

    public function assets(): array {
        // Signed casts prevent MySQL unsigned subtraction errors when bad or
        // legacy data has more active issuances than the registered quantity.
        $sql = "SELECT a.*,
                       CASE
                         WHEN a.status IN ('Maintenance', 'Retired') THEN 0
                         ELSE GREATEST(
                           CAST(a.quantity AS SIGNED) - CAST(COALESCE((
                             SELECT COUNT(*)
                             FROM asset_issuances i
                             WHERE i.asset_id = a.id
                               AND i.status IN ('Issued', 'Overdue', 'Not Returned')
                           ), 0) AS SIGNED),
                           0
                         )
                       END AS available_quantity
                FROM assets a
                WHERE NOT " . self::EXCLUDED_ASSET_SQL . "
                ORDER BY a.id DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function issuances(): array {
        $sql = 'SELECT i.*, a.asset_tag, a.name
                FROM asset_issuances i
                JOIN assets a ON a.id = i.asset_id
                WHERE NOT ' . self::EXCLUDED_ASSET_SQL . '
                ORDER BY i.id DESC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function stats(): array {
        $excluded = self::EXCLUDED_ASSET_SQL;
        $assets = $this->pdo->query('SELECT COUNT(*) FROM assets a WHERE NOT ' . $excluded)->fetchColumn();
        $issued = $this->pdo->query(
            "SELECT COUNT(*) FROM asset_issuances i
             JOIN assets a ON a.id = i.asset_id
             WHERE i.status = 'Issued' AND NOT " . $excluded
        )->fetchColumn();
        $maintenance = $this->pdo->query(
            "SELECT COUNT(*) FROM assets a WHERE a.status = 'Maintenance' AND NOT " . $excluded
        )->fetchColumn();

        return [
            'assets' => (int)$assets,
            'issued' => (int)$issued,
            'maintenance' => (int)$maintenance,
        ];
    }

    public function handle(string $action, array $data, ?array $user): string {
        if ($action === 'add_asset') {
            $quantity = max(1, (int)($data['quantity'] ?? 1));
            $tag = trim((string)($data['asset_tag'] ?? ''));
            $name = trim((string)($data['name'] ?? ''));
            if ($tag === '' || $name === '') {
                throw new RuntimeException('Asset tag and name are required.');
            }

            $status = (string)($data['status'] ?? 'Available');
            if (!in_array($status, ['Available', 'Issued', 'Maintenance', 'Retired'], true)) {
                throw new RuntimeException('Invalid asset status.');
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO assets(asset_tag, name, category, serial_number, status, quantity, location)
                 VALUES(?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $tag,
                $name,
                trim((string)($data['category'] ?? '')),
                trim((string)($data['serial_number'] ?? '')),
                $status,
                $quantity,
                trim((string)($data['location'] ?? '')),
            ]);
            $this->audit->record($user, self::MODULE, 'Register Asset', $tag);
            return 'Asset registered.';
        }

        if ($action === 'issue_asset') {
            $assetId = (int)($data['asset_id'] ?? 0);
            $employee = trim((string)($data['employee_name'] ?? ''));
            $issuedDate = trim((string)($data['issued_date'] ?? ''));
            $expectedReturn = trim((string)($data['expected_return'] ?? ''));
            if ($assetId <= 0 || $employee === '' || !self::isValidDate($issuedDate)) {
                throw new RuntimeException('Available asset, employee and a valid issued date are required.');
            }
            if ($expectedReturn !== '' && !self::isValidDate($expectedReturn)) {
                throw new RuntimeException('Expected return must be a valid date.');
            }
            if ($expectedReturn !== '' && $expectedReturn < $issuedDate) {
                throw new RuntimeException('Expected return cannot be earlier than the issued date.');
            }

            $this->pdo->beginTransaction();
            try {
                $lock = $this->pdo->prepare(
                    "SELECT id, asset_tag, name, serial_number, status, quantity,
                            (SELECT COUNT(*) FROM asset_issuances i
                             WHERE i.asset_id = assets.id
                               AND i.status IN ('Issued', 'Overdue', 'Not Returned')) AS active_issuances
                     FROM assets WHERE id = ? FOR UPDATE"
                );
                $lock->execute([$assetId]);
                $asset = $lock->fetch();

                if (!$asset || self::isExcludedAsset($asset)) {
                    throw new RuntimeException('Selected asset is no longer available.');
                }
                if ($asset['status'] !== 'Available' ||
                    ((int)$asset['quantity'] - (int)$asset['active_issuances']) <= 0) {
                    throw new RuntimeException('Selected asset is no longer available.');
                }

                $stmt = $this->pdo->prepare(
                    'INSERT INTO asset_issuances(asset_id, employee_name, issued_date, expected_return, status, notes)
                     VALUES(?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $assetId,
                    $employee,
                    $issuedDate,
                    $expectedReturn !== '' ? $expectedReturn : null,
                    'Issued',
                    trim((string)($data['notes'] ?? '')),
                ]);

                $remaining = (int)$asset['quantity'] - (int)$asset['active_issuances'] - 1;
                $nextAssetStatus = $remaining > 0 ? 'Available' : 'Issued';
                $this->pdo->prepare('UPDATE assets SET status = ? WHERE id = ?')
                    ->execute([$nextAssetStatus, $assetId]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            $this->audit->record($user, self::MODULE, 'Issue Equipment', 'Asset ID ' . $assetId);
            return 'Equipment issued.';
        }

        if ($action === 'update_issuance_status') {
            $role = (string)($user['role'] ?? '');
            if (!in_array($role, ['Administrator', 'Staff'], true)) {
                throw new RuntimeException('Only Administrator or Staff users can change borrowed item status.');
            }

            $id = (int)($data['issuance_id'] ?? 0);
            $status = (string)($data['status'] ?? '');
            if ($id <= 0) {
                throw new RuntimeException('Invalid issuance record.');
            }
            if (!in_array($status, ['Issued', 'Returned', 'Overdue', 'Not Returned'], true)) {
                throw new RuntimeException('Invalid issuance status.');
            }

            $this->pdo->beginTransaction();
            try {
                $issuanceStmt = $this->pdo->prepare(
                    'SELECT id, asset_id, status FROM asset_issuances WHERE id = ? FOR UPDATE'
                );
                $issuanceStmt->execute([$id]);
                $issuance = $issuanceStmt->fetch();
                if (!$issuance) {
                    throw new RuntimeException('Issuance record not found.');
                }

                $assetStmt = $this->pdo->prepare(
                    'SELECT id, asset_tag, name, serial_number, status, quantity
                     FROM assets WHERE id = ? FOR UPDATE'
                );
                $assetStmt->execute([(int)$issuance['asset_id']]);
                $asset = $assetStmt->fetch();
                if (!$asset || self::isExcludedAsset($asset)) {
                    throw new RuntimeException('The linked asset is no longer in the active inventory.');
                }

                $wasActive = in_array($issuance['status'], self::ACTIVE_ISSUANCE_STATUSES, true);
                $willBeActive = in_array($status, self::ACTIVE_ISSUANCE_STATUSES, true);
                if (!$wasActive && $willBeActive) {
                    if (in_array($asset['status'], ['Maintenance', 'Retired'], true)) {
                        throw new RuntimeException('A maintenance or retired asset cannot be re-issued.');
                    }
                    $activeStmt = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM asset_issuances
                         WHERE asset_id = ? AND status IN ('Issued', 'Overdue', 'Not Returned')"
                    );
                    $activeStmt->execute([(int)$asset['id']]);
                    if ((int)$activeStmt->fetchColumn() >= (int)$asset['quantity']) {
                        throw new RuntimeException('This asset has no remaining quantity available to issue.');
                    }
                }

                if ($status === 'Returned') {
                    $update = $this->pdo->prepare(
                        'UPDATE asset_issuances SET status = ?, return_date = CURDATE() WHERE id = ?'
                    );
                } else {
                    $update = $this->pdo->prepare(
                        'UPDATE asset_issuances SET status = ?, return_date = NULL WHERE id = ?'
                    );
                }
                $update->execute([$status, $id]);

                $activeStmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM asset_issuances
                     WHERE asset_id = ? AND status IN ('Issued', 'Overdue', 'Not Returned')"
                );
                $activeStmt->execute([(int)$asset['id']]);
                $activeCount = (int)$activeStmt->fetchColumn();

                // Preserve Maintenance/Retired. Only ordinary inventory switches
                // between Available and Issued as the active issuance count changes.
                if (in_array($asset['status'], ['Maintenance', 'Retired'], true)) {
                    $nextAssetStatus = $asset['status'];
                } else {
                    $nextAssetStatus = $activeCount > 0 ? 'Issued' : 'Available';
                }
                $this->pdo->prepare('UPDATE assets SET status = ? WHERE id = ?')
                    ->execute([$nextAssetStatus, (int)$asset['id']]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            $this->audit->record($user, self::MODULE, 'Update Issuance Status', 'Issuance ID ' . $id . ' -> ' . $status);
            return 'Borrowed item status updated to ' . $status . '.';
        }

        throw new RuntimeException('Unsupported Asset & Equipment action.');
    }

    private static function isValidDate(string $value): bool {
        if ($value === '') {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function isExcludedAsset(array $asset): bool {
        return (string)($asset['asset_tag'] ?? '') === 'AST-0003'
            && (string)($asset['name'] ?? '') === 'Network Printer'
            && (string)($asset['serial_number'] ?? '') === 'SN-GSMS-0003';
    }
}
