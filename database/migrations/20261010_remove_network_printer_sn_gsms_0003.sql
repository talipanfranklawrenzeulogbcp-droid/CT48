-- Removes only the requested Network Printer asset from existing installations.
-- Other assets and unrelated database content are not changed.
START TRANSACTION;
DELETE i
FROM asset_issuances AS i
JOIN assets AS a ON a.id = i.asset_id
WHERE a.asset_tag = 'AST-0003'
  AND a.name = 'Network Printer'
  AND a.serial_number = 'SN-GSMS-0003';

DELETE FROM assets
WHERE asset_tag = 'AST-0003'
  AND name = 'Network Printer'
  AND serial_number = 'SN-GSMS-0003';
COMMIT;
