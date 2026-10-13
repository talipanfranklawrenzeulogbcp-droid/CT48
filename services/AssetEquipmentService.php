<?php
final class AssetEquipmentService {
    private const MODULE = 'Asset & Equipment Issuance';
    private const ACTIVE_ISSUANCE_STATUSES = ['Issued', 'Overdue', 'Not Returned'];
    private const EXCLUDED_ASSET_TAG = 'AST-0003';
    private const EXCLUDED_ASSET_SERIAL = 'SN-GSMS-0003';
    private const EXCLUDED_ASSET_NAME = 'Network Printer';

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
                WHERE NOT " . self::excludedAssetSql('a') . "
                ORDER BY a.id DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function issuances(): array {
        $sql = "SELECT i.*, a.asset_tag, a.name
                FROM asset_issuances i
                JOIN assets a ON a.id = i.asset_id
                WHERE NOT " . self::excludedAssetSql('a') . "
                ORDER BY i.id DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function stats(): array {
        $assets = $this->pdo->query(
            "SELECT COUNT(*) FROM assets a WHERE NOT " . self::excludedAssetSql('a')
        )->fetchColumn();
        $issued = $this->pdo->query(
            "SELECT COUNT(*) FROM asset_issuances i
             JOIN assets a ON a.id = i.asset_id
             WHERE i.status IN ('Issued', 'Overdue', 'Not Returned')
               AND NOT " . self::excludedAssetSql('a')
        )->fetchColumn();
        $maintenance = $this->pdo->query(
            "SELECT COUNT(*) FROM assets a WHERE a.status = 'Maintenance' AND NOT " . self::excludedAssetSql('a')
        )->fetchColumn();

        return [
            'assets' => (int)$assets,
            'issued' => (int)$issued,
            'maintenance' => (int)$maintenance,
        ];
    }

    public function handle(string $action, array $data, ?array $user, array $files = []): string {
        if ($action === 'add_asset') {
            $quantity = max(1, (int)($data['quantity'] ?? 1));
            $tag = trim((string)($data['asset_tag'] ?? ''));
            $name = trim((string)($data['name'] ?? ''));
            $serial = trim((string)($data['serial_number'] ?? ''));
            if ($tag === '' || $name === '') {
                throw new RuntimeException('Asset tag and name are required.');
            }
            if (self::isExcludedAssetData($tag, $serial, $name)) {
                throw new RuntimeException('This equipment item is not supported in the active inventory.');
            }

            $status = (string)($data['status'] ?? 'Available');
            if (!in_array($status, ['Available', 'Issued', 'Maintenance', 'Retired'], true)) {
                throw new RuntimeException('Invalid asset status.');
            }
            $image = $this->prepareAssetImage($files);
            $imageStored = false;
            if ($image !== null) $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO assets(asset_tag, name, category, serial_number, status, quantity, location)
                     VALUES(?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $tag,
                    $name,
                    trim((string)($data['category'] ?? '')),
                    $serial,
                    $status,
                    $quantity,
                    trim((string)($data['location'] ?? '')),
                ]);
                if ($image !== null) {
                    $this->storeAssetImage($tag, $image);
                    $imageStored = true;
                    $this->pdo->commit();
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                if ($imageStored) $this->removeAssetImage($tag);
                throw $e;
            }
            $this->audit->record($user, self::MODULE, 'Register Asset', $tag . ($image !== null ? ' (photo uploaded)' : ''));
            return $image !== null ? 'Asset registered with its photo.' : 'Asset registered.';
        }

        if ($action === 'issue_asset') {
            $image = $this->prepareAssetImage($files);
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
                     FROM assets WHERE id = ? AND NOT " . self::excludedAssetSql() . " FOR UPDATE"
                );
                $lock->execute([$assetId]);
                $asset = $lock->fetch();

                if (!$asset) {
                    throw new RuntimeException('Selected asset is no longer available.');
                }
                if (in_array($asset['status'], ['Maintenance', 'Retired'], true) ||
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

            $photoMessage = '';
            if ($image !== null) {
                try {
                    $this->storeAssetImage((string)$asset['asset_tag'], $image);
                    $photoMessage = ' Asset photo uploaded.';
                } catch (Throwable $photoError) {
                    // The issuance itself has already committed. Report the photo issue separately
                    // rather than implying that the completed issuance failed and could be retried.
                    error_log('Asset issuance photo upload failed: ' . $photoError->getMessage());
                    $photoMessage = ' The item was issued, but its photo could not be saved; please retry the photo upload.';
                }
            }
            $this->audit->record($user, self::MODULE, 'Issue Equipment', 'Asset ID ' . $assetId . ($image !== null ? ' (photo upload attempted)' : ''));
            return 'Equipment issued.' . $photoMessage;
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
                     FROM assets WHERE id = ? AND NOT ' . self::excludedAssetSql() . ' FOR UPDATE'
                );
                $assetStmt->execute([(int)$issuance['asset_id']]);
                $asset = $assetStmt->fetch();
                if (!$asset) {
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
                    // For quantity > 1, an asset is fully issued only when every unit
                    // is actively assigned. Partial returns must make the remaining
                    // quantity available for another issuance.
                    $nextAssetStatus = $activeCount >= (int)$asset['quantity'] ? 'Issued' : 'Available';
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

    /** Validate an optional image before changing an inventory or issuance record. */
    private function prepareAssetImage(array $files): ?array {
        $file = $files['asset_image'] ?? null;
        if (!is_array($file)) return null;
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) return null;
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The asset image is too large. Choose an image no larger than 5 MB.',
                UPLOAD_ERR_PARTIAL => 'The asset image did not upload completely. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary folder is unavailable.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image.',
                UPLOAD_ERR_EXTENSION => 'The server blocked this image upload.',
                default => 'The asset image could not be uploaded. Please try again.',
            };
            throw new RuntimeException($message);
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || !is_readable($tmp)) {
            throw new RuntimeException('The uploaded image is not valid. Please choose the image again.');
        }
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new RuntimeException('Asset images must be 5 MB or smaller.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mime]) || @getimagesize($tmp) === false) {
            throw new RuntimeException('Unsupported image format. Upload a valid JPG, PNG, or WebP image.');
        }
        return ['tmp_name' => $tmp, 'extension' => $extensions[$mime]];
    }

    /** Photos are keyed by the unique asset tag, so the existing database schema stays unchanged. */
    private function storeAssetImage(string $assetTag, array $image): void {
        $extension = (string)($image['extension'] ?? '');
        if (!in_array($extension, ['jpg', 'png', 'webp'], true)) {
            throw new RuntimeException('Unsupported image format.');
        }

        $directory = dirname(__DIR__) . '/storage/asset_images';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Asset image storage could not be created.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Asset image storage is not writable by the application.');
        }

        $base = hash('sha256', strtoupper(trim($assetTag)));
        $temporary = $directory . '/.' . $base . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (!move_uploaded_file((string)($image['tmp_name'] ?? ''), $temporary)) {
            throw new RuntimeException('The asset photo could not be saved. Check storage folder permissions.');
        }
        $destination = $directory . '/' . $base . '.' . $extension;
        if (!@rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('The asset photo could not be finalized. Please try again.');
        }

        foreach (['jpg', 'png', 'webp'] as $oldExtension) {
            $old = $directory . '/' . $base . '.' . $oldExtension;
            if ($oldExtension !== $extension && is_file($old)) @unlink($old);
        }
        @chmod($destination, 0640);
    }

    private function removeAssetImage(string $assetTag): void {
        $directory = dirname(__DIR__) . '/storage/asset_images';
        $base = hash('sha256', strtoupper(trim($assetTag)));
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $path = $directory . '/' . $base . '.' . $extension;
            if (is_file($path)) @unlink($path);
        }
    }

    /** SQL predicate used to exclude a retired legacy inventory item without deleting its database rows. */
    private static function excludedAssetSql(string $alias = ''): string {
        $prefix = $alias === '' ? '' : $alias . '.';
        return "(COALESCE({$prefix}asset_tag, '') = '" . self::EXCLUDED_ASSET_TAG . "' OR "
            . "COALESCE({$prefix}serial_number, '') = '" . self::EXCLUDED_ASSET_SERIAL . "' OR "
            . "LOWER(TRIM(COALESCE({$prefix}name, ''))) = '" . strtolower(self::EXCLUDED_ASSET_NAME) . "')";
    }

    private static function isExcludedAssetData(string $tag, string $serial, string $name): bool {
        return strcasecmp(trim($tag), self::EXCLUDED_ASSET_TAG) === 0
            || strcasecmp(trim($serial), self::EXCLUDED_ASSET_SERIAL) === 0
            || strcasecmp(trim($name), self::EXCLUDED_ASSET_NAME) === 0;
    }

    private static function isValidDate(string $value): bool {
        if ($value === '') {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

}
