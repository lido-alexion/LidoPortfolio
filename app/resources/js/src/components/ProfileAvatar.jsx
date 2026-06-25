import React from 'react';
import { userInitial } from '../utils/userDisplay';

export default function ProfileAvatar({
    user,
    className = '',
    size = 28,
    menu = false,
}) {
    const photoUrl = user?.profile_photo_url;
    const initial = userInitial(user);

    if (photoUrl) {
        const imgClass = menu
            ? `lido-profile-menu-avatar rounded-circle ${className}`.trim()
            : `lido-profile-avatar rounded-circle ${className}`.trim();

        return (
            <img
                src={photoUrl}
                alt=""
                className={imgClass}
                width={size}
                height={size}
                style={{ objectFit: 'cover' }}
                aria-hidden="true"
            />
        );
    }

    const baseClass = menu
        ? 'lido-profile-menu-avatar rounded-circle d-flex align-items-center justify-content-center'
        : 'lido-profile-avatar';

    return (
        <span
            className={`${baseClass} ${className}`.trim()}
            style={menu ? { width: size, height: size } : { width: size, height: size }}
            aria-hidden="true"
        >
            {initial}
        </span>
    );
}
