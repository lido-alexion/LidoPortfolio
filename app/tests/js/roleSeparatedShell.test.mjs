import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const src = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const app = readFileSync(join(src, 'App.jsx'), 'utf8');
const header = readFileSync(join(src, 'components/AppHeader.jsx'), 'utf8');
const sidebar = readFileSync(join(src, 'components/sidebar/Sidebar.jsx'), 'utf8');

test('authenticated shell selects a distinct route set by account role', () => {
    assert.match(app, /function AdminAppRoutes\(\)/);
    assert.match(app, /user\.is_admin \? <AdminAppRoutes \/> : <AppRoutes \/>/);
    assert.match(app, /<Route path="\/" element=\{<Navigate to="\/settings\/users" replace \/>\} \/>/);
    assert.match(app, /<Route path="\*" element=\{<Navigate to="\/settings\/users" replace \/>\} \/>/);
});

test('Admin shell exposes only administrative and shared account navigation', () => {
    assert.match(sidebar, /if \(!user\?\.is_admin\)/);
    assert.match(sidebar, /'users'/);
    assert.match(sidebar, /'data-quality'/);
    assert.match(sidebar, /'profile'/);
    assert.match(sidebar, /!user\?\.is_admin && <SidebarFavourites/);
    assert.match(sidebar, /!user\?\.is_admin && <SidebarQuickActions/);
});

test('Admin header never renders the Investor portfolio switcher', () => {
    assert.match(header, /user && !user\.is_admin && <PortfolioSwitcher \/>/);
});
