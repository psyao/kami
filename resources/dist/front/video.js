// Kami's Video Block facade (ADR 0016): the provider's player loads on a click only, so a visit tells YouTube or Vimeo nothing.
// One listener on the document, so a video added later, by a Livewire update or an Inertia visit, works too.
document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('[data-kami-video] a') : null;

    // A modified click keeps the link's own behaviour, such as a new tab.
    if (link === null || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();
    const facade = link.closest('[data-kami-video]');
    const player = document.createElement('iframe');
    player.src = facade.dataset.kamiVideo;
    player.title = facade.dataset.title;
    player.width = '560';
    player.height = '315';
    player.allow = 'autoplay; fullscreen; picture-in-picture';
    player.allowFullscreen = true;
    facade.replaceChildren(player);
    // The link held the focus: it moves to the player rather than to the page's start.
    player.focus();
});
