/**
 * Flora Sabão — Scripts Gerais da Interface
 */

document.addEventListener("DOMContentLoaded", () => {
  // 1. Fechar alertas flash de sucesso automaticamente após 4 segundos
  const alertas = document.querySelectorAll(".alert-success");
  alertas.forEach((alerta) => {
    setTimeout(() => {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(alerta);
      if (bsAlert) {
        bsAlert.close();
      }
    }, 4000);
  });

  // 2. Confirmação genérica para links com atributo data-confirm
  document.querySelectorAll("[data-confirm]").forEach((el) => {
    el.addEventListener("click", (e) => {
      const mensagem =
        el.getAttribute("data-confirm") || "Deseja realmente prosseguir?";
      if (!confirm(mensagem)) {
        e.preventDefault();
      }
    });
  });

  // 3. Inicializar Tooltips do Bootstrap
  const tooltipTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="tooltip"]'),
  );
  tooltipTriggerList.map(
    (tooltipTriggerEl) => new bootstrap.Tooltip(tooltipTriggerEl),
  );
});
