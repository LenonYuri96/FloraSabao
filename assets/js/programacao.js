/**
 * Flora Sabão — Sequenciamento & Gestão de Setup (programacao.js)
 */

document.addEventListener("DOMContentLoaded", () => {
  // Realça visualmente linhas com alerta de contaminação cruzada ao passar o mouse
  const linhasCriticas = document.querySelectorAll(
    "tr.table-danger, tr.table-warning",
  );
  linhasCriticas.forEach((linha) => {
    linha.addEventListener("mouseenter", () => {
      linha.style.filter = "brightness(0.96)";
    });
    linha.addEventListener("mouseleave", () => {
      linha.style.filter = "none";
    });
  });

  // Notificação com contador regressivo ao executar a heurística de setup
  const formHeuristica = document.querySelector(
    'form[action="programacao.php"]',
  );
  if (formHeuristica) {
    formHeuristica.addEventListener("submit", (e) => {
      const btn = formHeuristica.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML =
          '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Otimizando sequenciamento...';
      }
    });
  }
});
