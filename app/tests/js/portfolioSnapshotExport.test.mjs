import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const page = readFileSync(join(root, 'pages/PortfolioSnapshotsPage.jsx'), 'utf8');
const button = readFileSync(join(root, 'components/ExportDataButton.jsx'), 'utf8');
const basket = readFileSync(join(root, 'components/ExportBasketPanel.jsx'), 'utf8');
const registry = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../../app/Services/Export/ExportDatasetRegistry.php'), 'utf8');

test('snapshot selection sends stable IDs and resets when portfolio or range changes', () => {
    assert.match(page, /aria-label=\{`Select snapshot \$\{row\.snapshot_date\}`\}/);
    assert.match(page, /selectedIds=\{selectedIds\}/);
    assert.match(page, /useEffect\(\(\) => setSelectedIds\(\[\]\), \[profileId, rangeId\]\)/);
    assert.match(button, /selected = selectedIds/);
    assert.doesNotMatch(button, /Selected row IDs/);
});

test('basket field and scope controls are derived from registered catalog metadata', () => {
    assert.match(basket, /const fields = dataset\?\.fields \|\| \[\]/);
    assert.match(basket, /const scopes = dataset\?\.scopes \|\| \[\]/);
    assert.match(basket, /scopes\.map\(\(scope\)/);
    assert.match(basket, /fields\.map\(\(field\)/);
    assert.match(basket, /!dataset\.scopes\?\.includes\(item\.scope\)/);
    assert.match(registry, /'dashboard-summary'.*'scopes' => \['full'\]/);
    assert.match(registry, /'portfolio-growth'.*'scopes' => \['current', 'full'\]/);
    assert.match(registry, /'portfolio-snapshots'.*'scopes' => \['current', 'full', 'selected'\]/);
});

test('basket current filters and selected stable IDs follow their scope contracts', () => {
    assert.match(basket, /const SNAPSHOT_RANGES = \['90d', '180d', '365d', 'all'\]/);
    assert.match(basket, /const SORT_DIRECTIONS = \['asc', 'desc'\]/);
    assert.match(basket, /item\.scope === 'current'/);
    assert.match(basket, /dataset\?\.scopes\?\.includes\('current'\) && item\.scope === 'current'/);
    assert.match(basket, /sort_by: 'snapshot_date'/);
    assert.match(basket, /item\.scope === 'selected'/);
    assert.match(basket, /dataset\?\.scopes\?\.includes\('selected'\) && item\.scope === 'selected'/);
    assert.match(basket, /selected: normalizedSelectedIds\(event\.target\.value\)/);
    assert.match(basket, /Stable selected row IDs/);
    assert.match(basket, /at least one stable row ID/i);
    assert.match(basket, /Select at least one field\./);
    assert.match(basket, /api\.put\('\/exports\/basket', \{ items \}\)/);
    assert.match(basket, /api\.post\('\/exports\/basket\/export'\)/);
});
