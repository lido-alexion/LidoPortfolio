import test from 'node:test';
import assert from 'node:assert/strict';
import { buildAdminOperationalAlertsToastMessage } from '../../resources/js/src/utils/adminOperationalAlertsMessage.js';

test('buildAdminOperationalAlertsToastMessage singular and plural', () => {
    assert.match(
        buildAdminOperationalAlertsToastMessage(1),
        /1 operational alert/,
    );
    assert.match(
        buildAdminOperationalAlertsToastMessage(3),
        /3 operational alerts/,
    );
});
