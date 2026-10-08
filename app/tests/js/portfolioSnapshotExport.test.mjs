import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const page = readFileSync(join(root, 'pages/PortfolioSnapshotsPage.jsx'), 'utf8');
const button = readFileSync(join(root, 'components/ExportDataButton.jsx'), 'utf8');

test('snapshot selection sends stable IDs and resets when portfolio or range changes', () => {
    assert.match(page, /aria-label=\{`Select snapshot \$\{row\.snapshot_date\}`\}/);
    assert.match(page, /selectedIds=\{selectedIds\}/);
    assert.match(page, /useEffect\(\(\) => setSelectedIds\(\[\]\), \[profileId, rangeId\]\)/);
    assert.match(button, /selected = selectedIds/);
    assert.doesNotMatch(button, /Selected row IDs/);
});
