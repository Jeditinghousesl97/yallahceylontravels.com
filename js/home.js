document.addEventListener('DOMContentLoaded', () => {

  /* TESTIMONIAL SLIDER */
  const track    = document.getElementById('testiTrack');
  const prevBtn  = document.getElementById('testiPrev');
  const nextBtn  = document.getElementById('testiNext');

  if (!track) return;

  const cards    = track.querySelectorAll('.testimonial-card');
  let   index    = 0;
  let   autoPlay;

  /* How many cards are visible at current breakpoint */
  function visibleCount() {
    if (window.innerWidth < 768)  return 1;
    if (window.innerWidth < 1024) return 2;
    return 3;
  }

  function maxIndex() {
    return Math.max(0, cards.length - visibleCount());
  }

  function getCardWidth() {
    return cards[0].offsetWidth + 28; // 28 = gap in CSS
  }

  function update() {
    if (index < 0)          index = maxIndex();
    if (index > maxIndex()) index = 0;
    track.style.transform = `translateX(-${index * getCardWidth()}px)`;
  }

  function startAuto() {
    clearInterval(autoPlay);
    autoPlay = setInterval(() => { index++; update(); }, 5000);
  }

  /* Init */
  update();
  startAuto();

  nextBtn?.addEventListener('click', () => { index++; update(); startAuto(); });
  prevBtn?.addEventListener('click', () => { index--; update(); startAuto(); });

  window.addEventListener('resize', update);

});

/* Partners marquee — duplicate track for seamless loop */
(function() {
  const track = document.getElementById('partnersTrack');
  const wrap  = document.getElementById('partnersMarquee');
  if (!track || !wrap) return;
  // Clone track for infinite loop
  const clone = track.cloneNode(true);
  clone.setAttribute('aria-hidden', 'true');
  wrap.appendChild(clone);
})();

/* World times */
(function() {
  const cards = Array.from(document.querySelectorAll('.world-time-card[data-timezone]'));
  if (!cards.length) return;

  function updateWorldTimes() {
    const now = new Date();
    cards.forEach((card) => {
      const timezone = card.getAttribute('data-timezone');
      const valueEl = card.querySelector('.world-time-value');
      const hourHand = card.querySelector('.analog-hand.hour');
      const minuteHand = card.querySelector('.analog-hand.minute');
      const secondHand = card.querySelector('.analog-hand.second');
      if (!timezone || !valueEl) return;
      try {
        const digital = new Intl.DateTimeFormat('en-GB', {
          timeZone: timezone,
          hour: '2-digit',
          minute: '2-digit',
          hour12: false
        });
        valueEl.textContent = digital.format(now);

        const parts = new Intl.DateTimeFormat('en-GB', {
          timeZone: timezone,
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hour12: false
        }).formatToParts(now);
        const getPart = (type) => {
          const found = parts.find((p) => p.type === type);
          return found ? parseInt(found.value, 10) : 0;
        };
        const h24 = getPart('hour');
        const m = getPart('minute');
        const s = getPart('second');

        const h12 = h24 % 12;
        const hourDeg = (h12 * 30) + (m * 0.5) + (s / 120);
        const minDeg = (m * 6) + (s * 0.1);
        const secDeg = s * 6;

        if (hourHand) hourHand.style.transform = `translateX(-50%) rotate(${hourDeg}deg)`;
        if (minuteHand) minuteHand.style.transform = `translateX(-50%) rotate(${minDeg}deg)`;
        if (secondHand) secondHand.style.transform = `translateX(-50%) rotate(${secDeg}deg)`;
      } catch (err) {
        valueEl.textContent = '--:--';
      }
    });
  }

  updateWorldTimes();
  setInterval(updateWorldTimes, 1000);
})();
