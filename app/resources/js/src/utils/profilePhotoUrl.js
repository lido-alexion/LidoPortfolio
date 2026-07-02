import { appUrl } from '../appBase';

/**
 * Same-origin profile photo URL for <img src> (subdirectory-safe).
 * @param {{ profile_photo_url?: string | null } | null | undefined} user
 */
export function profilePhotoUrl(user) {
    const raw = user?.profile_photo_url;
    if (!raw) {
        return null;
    }
    const queryIndex = raw.indexOf('?');
    const query = queryIndex >= 0 ? raw.slice(queryIndex) : '';
    return `${appUrl('/api/profile/photo')}${query}`;
}
