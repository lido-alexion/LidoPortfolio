export function userDisplayName(user) {
    const name = user?.name?.trim();
    if (name) {
        return name;
    }
    return user?.email || '?';
}

export function userInitial(user) {
    return userDisplayName(user).charAt(0).toUpperCase();
}
