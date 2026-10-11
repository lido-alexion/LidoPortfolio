import React from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import TaxEvidenceEntryPanel from '../../../resources/js/src/components/TaxEvidenceEntryPanel.jsx';
import { apiMock } from './helpers/mockApi.js';

describe('tax evidence recovery', () => {
    it('announces a failed dividend write, retains the draft and changes reporting only after success', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [] } });
        apiMock.post.mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValueOnce({ data: {} });
        const changed = vi.fn(); render(<TaxEvidenceEntryPanel financialYear="2026-27" onChanged={changed} />);
        fireEvent.change(screen.getByLabelText('Dividend amount'), { target: { value: '100.50' } });
        fireEvent.change(screen.getByLabelText('Dividend reference'), { target: { value: 'Synthetic statement reference' } });
        fireEvent.click(screen.getByRole('button', { name: 'Record dividend' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Tax evidence could not be recorded');
        expect(screen.getByLabelText('Dividend amount')).toHaveValue(100.5); expect(changed).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Record dividend' }));
        await vi.waitFor(() => expect(changed).toHaveBeenCalledTimes(1)); expect(apiMock.post).toHaveBeenCalledTimes(2);
    });
    it('keeps a failed CSV preview non-importable and requires an explicit valid preview for a corrected file', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [] } });
        apiMock.post.mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValueOnce({ data: { data: { valid: true, candidate_rows: 1, duplicates: 0, imported: 0 } } });
        const changed = vi.fn(); render(<TaxEvidenceEntryPanel financialYear="2026-27" onChanged={changed} />);
        const upload = screen.getByLabelText('Dividend CSV · generic-dividend-csv.v1');
        fireEvent.change(upload, { target: { files: [new File(['synthetic,fixture'], 'initial.csv', { type: 'text/csv' })] } });
        fireEvent.click(screen.getByRole('button', { name: 'Preview', exact: true }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Dividend CSV preview could not be loaded');
        expect(screen.getByRole('button', { name: 'Import validated rows' })).toBeDisabled(); expect(changed).not.toHaveBeenCalled();
        fireEvent.change(upload, { target: { files: [new File(['synthetic,corrected'], 'corrected.csv', { type: 'text/csv' })] } });
        expect(screen.getByRole('button', { name: 'Import validated rows' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Preview', exact: true }));
        await vi.waitFor(() => expect(screen.getByRole('button', { name: 'Import validated rows' })).toBeEnabled());
        expect(apiMock.post).toHaveBeenCalledTimes(2); for (const [, body] of apiMock.post.mock.calls) expect(body.get('dry_run')).toBe('1'); expect(changed).not.toHaveBeenCalled();
    });
    it('invalidates an earlier successful preview when a new preview fails', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [] } });
        apiMock.post.mockResolvedValueOnce({ data: { data: { valid: true, candidate_rows: 1 } } })
            .mockRejectedValueOnce({ response: { status: 503 } });
        render(<TaxEvidenceEntryPanel financialYear="2026-27" onChanged={vi.fn()} />);
        fireEvent.change(screen.getByLabelText('Dividend CSV · generic-dividend-csv.v1'), { target: { files: [new File(['synthetic,fixture'], 'dividend.csv', { type: 'text/csv' })] } });
        fireEvent.click(screen.getByRole('button', { name: 'Preview', exact: true }));
        await vi.waitFor(() => expect(screen.getByRole('button', { name: 'Import validated rows' })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: 'Preview', exact: true }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Dividend CSV preview could not be loaded');
        expect(screen.getByRole('button', { name: 'Import validated rows' })).toBeDisabled();
        expect(apiMock.post).toHaveBeenCalledTimes(2);
    });
    it('keeps the selected file fixed while its preview is pending', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [] } });
        let finish;
        apiMock.post.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
        render(<TaxEvidenceEntryPanel financialYear="2026-27" onChanged={vi.fn()} />);
        const upload = screen.getByLabelText('Dividend CSV · generic-dividend-csv.v1');
        const reviewedFile = new File(['synthetic,fixture'], 'dividend.csv', { type: 'text/csv' });
        fireEvent.change(upload, { target: { files: [reviewedFile] } });
        fireEvent.click(screen.getByRole('button', { name: 'Preview', exact: true }));
        expect(upload).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Import validated rows' })).toBeDisabled();
        await act(async () => finish({ data: { data: { valid: true, candidate_rows: 1 } } }));
        expect(upload).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Import validated rows' })).toBeEnabled();
        expect(apiMock.post.mock.calls[0][1].get('file')).toBe(reviewedFile);
    });
});
