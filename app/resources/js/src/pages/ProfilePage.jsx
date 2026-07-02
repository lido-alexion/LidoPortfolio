import React, { useEffect, useRef, useState } from 'react';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import { showToast } from '../toast';
import { userDisplayName, userInitial } from '../utils/userDisplay';
import { profilePhotoUrl } from '../utils/profilePhotoUrl';

const PROFILE_PHOTO_SIZE_PX = 360;

function validationMessage(error) {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) {
            return first;
        }
    }
    return error?.response?.data?.message || 'Something went wrong. Please try again.';
}

export default function ProfilePage() {
    const { user, refreshUser } = useAuth();
    const fileInputRef = useRef(null);

    const [name, setName] = useState('');
    const [nameSaving, setNameSaving] = useState(false);

    const [currentPassword, setCurrentPassword] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [passwordSaving, setPasswordSaving] = useState(false);

    const [photoUploading, setPhotoUploading] = useState(false);
    const [photoRemoving, setPhotoRemoving] = useState(false);
    const photoBusy = photoUploading || photoRemoving;

    useEffect(() => {
        setName(user?.name?.trim() || '');
    }, [user]);

    const saveName = async (e) => {
        e.preventDefault();
        setNameSaving(true);
        try {
            await api.put('/profile', { name: name.trim() });
            await refreshUser();
            showToast('Name updated');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setNameSaving(false);
        }
    };

    const savePassword = async (e) => {
        e.preventDefault();
        setPasswordSaving(true);
        try {
            await api.put('/profile/password', {
                current_password: currentPassword,
                password,
                password_confirmation: passwordConfirmation,
            });
            setCurrentPassword('');
            setPassword('');
            setPasswordConfirmation('');
            showToast('Password updated');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setPasswordSaving(false);
        }
    };

    const uploadPhoto = async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) {
            return;
        }

        const allowedTypes = ['image/jpeg', 'image/png'];
        if (!allowedTypes.includes(file.type)) {
            showToast('Only JPEG and PNG images are supported.', 'danger');
            return;
        }

        const form = new FormData();
        form.append('photo', file);

        setPhotoUploading(true);
        try {
            await api.post('/profile/photo', form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            await refreshUser();
            showToast('Profile photo updated');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setPhotoUploading(false);
        }
    };

    const removePhoto = async () => {
        setPhotoRemoving(true);
        try {
            await api.delete('/profile/photo');
            await refreshUser();
            showToast('Profile photo removed');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setPhotoRemoving(false);
        }
    };

    const photoSrc = profilePhotoUrl(user);
    const hasPhoto = Boolean(photoSrc);
    const photoActionLabel = hasPhoto ? 'Change profile picture' : 'Upload profile picture';
    const initial = userInitial(user);

    const openPhotoPicker = () => {
        if (!photoBusy) {
            fileInputRef.current?.click();
        }
    };

    return (
        <div className="d-grid gap-3">
            <div className="card">
                <div className="card-header">
                    <h2 className="h6 mb-0">Profile photo</h2>
                </div>
                <div className="card-body d-flex flex-column align-items-center gap-2">
                    <div className={`lido-profile-photo-picker${hasPhoto ? ' lido-profile-photo-picker--has-photo' : ''}${photoUploading ? ' lido-profile-photo-picker--uploading' : ''}`}>
                        <button
                            type="button"
                            className="lido-profile-photo-hit"
                            onClick={openPhotoPicker}
                            disabled={photoBusy}
                            title={photoUploading ? 'Uploading…' : photoActionLabel}
                            aria-label={photoUploading ? 'Uploading profile picture' : photoActionLabel}
                            aria-busy={photoUploading}
                        >
                            {hasPhoto ? (
                                <img
                                    src={photoSrc}
                                    alt=""
                                    className="lido-profile-page-avatar rounded-circle"
                                    width={PROFILE_PHOTO_SIZE_PX}
                                    height={PROFILE_PHOTO_SIZE_PX}
                                />
                            ) : (
                                <span
                                    className="lido-profile-page-avatar lido-profile-page-avatar-fallback rounded-circle d-flex align-items-center justify-content-center"
                                    aria-hidden="true"
                                >
                                    {initial}
                                </span>
                            )}
                        </button>
                        {photoUploading && (
                            <div className="lido-profile-photo-loader" aria-live="polite">
                                <div className="spinner-border text-light" role="status">
                                    <span className="visually-hidden">Uploading profile picture…</span>
                                </div>
                            </div>
                        )}
                        {hasPhoto && (
                            <button
                                type="button"
                                className="lido-profile-photo-remove"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    removePhoto();
                                }}
                                disabled={photoBusy}
                            >
                                Remove photo
                            </button>
                        )}
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                            className="d-none"
                            onChange={uploadPhoto}
                        />
                    </div>
                    <p className="text-muted small mb-0 text-center">
                        JPEG or PNG, max 5 MB. Click the photo to upload or change.
                    </p>
                </div>
            </div>

            <form className="card" onSubmit={saveName}>
                <div className="card-header">
                    <h2 className="h6 mb-0">Account</h2>
                </div>
                <div className="card-body d-grid gap-3">
                    <div>
                        <label htmlFor="profile-name" className="form-label">Display name</label>
                        <input
                            id="profile-name"
                            type="text"
                            className="form-control"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            maxLength={255}
                            placeholder={user?.email || 'Your name'}
                        />
                        <div className="form-text">
                            Shown in the header when set; otherwise your username (
                            {user?.email}
                            ) is used.
                        </div>
                    </div>
                    <div>
                        <label htmlFor="profile-username" className="form-label">Username</label>
                        <input
                            id="profile-username"
                            type="text"
                            className="form-control"
                            value={user?.email || ''}
                            disabled
                            readOnly
                        />
                    </div>
                    <div>
                        <button
                            type="submit"
                            className="btn btn-primary btn-sm"
                            disabled={nameSaving}
                        >
                            {nameSaving ? 'Saving…' : 'Save name'}
                        </button>
                    </div>
                </div>
            </form>

            <form className="card" onSubmit={savePassword}>
                <div className="card-header">
                    <h2 className="h6 mb-0">Change password</h2>
                </div>
                <div className="card-body d-grid gap-3">
                    <div>
                        <label htmlFor="profile-current-password" className="form-label">
                            Current password
                        </label>
                        <input
                            id="profile-current-password"
                            type="password"
                            className="form-control"
                            value={currentPassword}
                            onChange={(e) => setCurrentPassword(e.target.value)}
                            autoComplete="current-password"
                            required
                        />
                    </div>
                    <div>
                        <label htmlFor="profile-new-password" className="form-label">
                            New password
                        </label>
                        <input
                            id="profile-new-password"
                            type="password"
                            className="form-control"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            autoComplete="new-password"
                            minLength={8}
                            required
                        />
                    </div>
                    <div>
                        <label htmlFor="profile-confirm-password" className="form-label">
                            Confirm new password
                        </label>
                        <input
                            id="profile-confirm-password"
                            type="password"
                            className="form-control"
                            value={passwordConfirmation}
                            onChange={(e) => setPasswordConfirmation(e.target.value)}
                            autoComplete="new-password"
                            minLength={8}
                            required
                        />
                    </div>
                    <div>
                        <button
                            type="submit"
                            className="btn btn-primary btn-sm"
                            disabled={passwordSaving}
                        >
                            {passwordSaving ? 'Updating…' : 'Update password'}
                        </button>
                    </div>
                </div>
            </form>

            <p className="text-muted small mb-0">
                Signed in as
                {' '}
                {userDisplayName(user)}
            </p>
        </div>
    );
}
