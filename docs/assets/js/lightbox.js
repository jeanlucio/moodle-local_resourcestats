document.addEventListener('DOMContentLoaded', () => {
  const thumbs = Array.from(document.querySelectorAll('.screenshot-thumb'));
  if (thumbs.length === 0) {
    return;
  }

  const overlay = document.createElement('div');
  overlay.className = 'lightbox-overlay';
  overlay.hidden = true;
  overlay.innerHTML = `
    <button type="button" class="lightbox-close" aria-label="Close">&times;</button>
    <button type="button" class="lightbox-nav lightbox-prev" aria-label="Previous">&#8249;</button>
    <figure class="lightbox-figure">
      <img alt="">
      <figcaption></figcaption>
    </figure>
    <button type="button" class="lightbox-nav lightbox-next" aria-label="Next">&#8250;</button>
  `;
  document.body.appendChild(overlay);

  const img = overlay.querySelector('img');
  const caption = overlay.querySelector('figcaption');
  const closeBtn = overlay.querySelector('.lightbox-close');
  const prevBtn = overlay.querySelector('.lightbox-prev');
  const nextBtn = overlay.querySelector('.lightbox-next');

  // Thumbnails sharing a data-gallery value form one browsable set; recomputed
  // on each open so prev/next only ever cycles within that set.
  let group = [];
  let index = 0;
  let lastFocused = null;

  const render = () => {
    const thumb = group[index];
    img.src = thumb.dataset.full;
    img.alt = thumb.dataset.caption || '';
    caption.textContent = thumb.dataset.caption || '';
  };

  const open = (thumb) => {
    group = thumbs.filter((candidate) => candidate.dataset.gallery === thumb.dataset.gallery);
    index = group.indexOf(thumb);
    prevBtn.hidden = group.length <= 1;
    nextBtn.hidden = group.length <= 1;
    lastFocused = document.activeElement;
    render();
    overlay.hidden = false;
    closeBtn.focus();
  };

  const close = () => {
    overlay.hidden = true;
    img.src = '';
    if (lastFocused) {
      lastFocused.focus();
    }
  };

  const step = (delta) => {
    index = (index + delta + group.length) % group.length;
    render();
  };

  thumbs.forEach((thumb) => {
    thumb.addEventListener('click', () => open(thumb));
  });

  closeBtn.addEventListener('click', close);
  prevBtn.addEventListener('click', () => step(-1));
  nextBtn.addEventListener('click', () => step(1));

  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) {
      close();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (overlay.hidden) {
      return;
    }
    if (event.key === 'Escape') {
      close();
    } else if (event.key === 'ArrowLeft') {
      step(-1);
    } else if (event.key === 'ArrowRight') {
      step(1);
    }
  });
});
