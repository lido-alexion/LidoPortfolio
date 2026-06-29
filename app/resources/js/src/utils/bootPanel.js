/** Hide the full-screen boot diagnostic overlay once React has mounted. */
export function hideBootPanel() {
    const panel = document.getElementById('lido-boot-panel');
    if (panel) {
        panel.hidden = true;
    }
}
