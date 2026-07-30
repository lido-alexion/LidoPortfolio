import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { getEnabledQuickActions, runQuickAction } from '../../utils/quickActionRunner';
import NavMenuItem from './NavMenuItem';

export default function SidebarQuickActions({
    collapsed,
    accessCtx,
    onDone,
}) {
    const navigate = useNavigate();
    const [busyId, setBusyId] = useState(null);
    const actions = getEnabledQuickActions(accessCtx || {});

    if (actions.length === 0) {
        return null;
    }

    const handleClick = async (action) => {
        if (busyId) {
            return;
        }
        setBusyId(action.id);
        try {
            await runQuickAction(action, { navigate, onDone });
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div className="lido-sidebar-block lido-sidebar-block--actions">
            {!collapsed && (
                <div className="lido-sidebar-block-header">
                    <span className="lido-sidebar-block-title">Quick Actions</span>
                </div>
            )}
            {collapsed && (
                <div className="lido-sidebar-section-divider" aria-hidden="true" />
            )}
            <ul className="lido-sidebar-list lido-sidebar-list--actions">
                {actions.map((action) => (
                    <li key={action.id}>
                        <NavMenuItem
                            title={action.title}
                            icon={action.icon}
                            collapsed={collapsed}
                            busy={busyId === action.id}
                            variant="action"
                            showActiveBar={false}
                            onClick={() => handleClick(action)}
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}
