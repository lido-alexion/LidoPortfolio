import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiMock } from './helpers/mockApi.js';
import VpsHealthAdminPage from '../../../resources/js/src/pages/VpsHealthAdminPage';

describe('VPS health metric tips', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('opens the swap guidance and copies its optional command', async () => {
        apiMock.get.mockResolvedValue({
            data: {
                data: {
                    latest: {
                        sampled_at: '2026-10-08T12:00:00Z',
                        status: 'ok',
                        issues: [],
                        metrics: {
                            cpus: 2,
                            load_per_core: 0.2,
                            load1: 0.4,
                            ram_available_percent: 70,
                            ram_available_bytes: 6 * (1024 ** 3),
                            root_used_percent: 70,
                            swap_used_percent: 50,
                            fpm: { 'active processes': 1, 'idle processes': 4, 'listen queue': 0, 'max children': 5 },
                            nginx: { '499': 0, '502': 0, '503': 0, '504': 0 },
                        },
                    },
                    samples: [],
                    sample_count: 1,
                    last_sample_age_seconds: 20,
                },
            },
        });
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', Object.assign(Object.create(navigator), { clipboard: { writeText } }));

        render(<VpsHealthAdminPage />);

        expect(await screen.findAllByRole('button', { name: /^Tips for / })).toHaveLength(6);
        fireEvent.click(screen.getByRole('button', { name: 'Tips for Swap used' }));

        const dialog = screen.getByRole('dialog', { name: 'Swap used tips' });
        expect(within(dialog).getByText('vmstat -w 1 10')).toBeInTheDocument();
        expect(within(dialog).getByText(/Do not run it when RAM is tight/)).toBeInTheDocument();

        fireEvent.click(within(dialog).getAllByRole('button', { name: 'Copy command' })[2]);
        await waitFor(() => expect(writeText).toHaveBeenCalledWith('sudo swapoff /swapfile && sudo swapon /swapfile'));
        expect(within(dialog).getByRole('status')).toHaveTextContent('Command copied.');

        fireEvent.keyDown(window, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
