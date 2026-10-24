/**
 * Flora Sabão — Lógica de Chão de Fábrica e Ordens de Produção (op.js)
 */

document.addEventListener("DOMContentLoaded", () => {
  // 1. Controle do Modal de Apontamento Final de Produção
  const modalApontarEl = document.getElementById("modalApontar");
  if (modalApontarEl) {
    const modalApontar = bootstrap.Modal.getOrCreateInstance(modalApontarEl);
    const apontarOpId = document.getElementById("apontar_op_id");
    const apontarOpNum = document.getElementById("apontar_op_num");
    const apontarOpQtd = document.getElementById("apontar_op_qtd");
    const apontarBoas = document.getElementById("apontar_boas");
    const apontarRefugo = document.getElementById("apontar_refugo");

    document.querySelectorAll(".btn-apontar").forEach((btn) => {
      btn.addEventListener("click", () => {
        const opId = btn.getAttribute("data-op-id");
        const opNum = btn.getAttribute("data-op-num");
        const qtd = btn.getAttribute("data-qtd");

        apontarOpId.value = opId;
        apontarOpNum.textContent = opNum;
        apontarOpQtd.textContent = qtd;
        apontarBoas.value = qtd;
        apontarRefugo.value = 0;

        modalApontar.show();
      });
    });
  }

  // 2. Cálculo e Renderização Dinâmica da Explosão de BOM
  const modalBomEl = document.getElementById("modalBomExplosion");
  if (modalBomEl) {
    const modalBom = bootstrap.Modal.getOrCreateInstance(modalBomEl);
    const container = document.getElementById("conteudoBomExplosion");

    document.querySelectorAll(".btn-ver-bom").forEach((btn) => {
      btn.addEventListener("click", () => {
        const prodId = btn.getAttribute("data-prod-id");
        const opQtd = parseInt(btn.getAttribute("data-qtd"), 10) || 1;
        const opNum = btn.getAttribute("data-op-num");

        container.innerHTML = `
          <div class="alert alert-secondary py-2 small mb-3">
            Simulação MRP para <strong>${opNum}</strong>: Lote de <strong>${opQtd} barras</strong>.
          </div>
          <div class="text-center py-4">
            <div class="spinner-border text-success" role="status"></div>
            <p class="small text-muted mt-2">Calculando explosão de materiais...</p>
          </div>
        `;

        modalBom.show();

        // Requisição para a API
        const apiUrl = `../api/op_api.php?action=get_bom&produto_id=${prodId}&qtd=${opQtd}`;
        fetch(apiUrl)
          .then((res) => {
            if (!res.ok) throw new Error("Erro na resposta da rede");
            return res.json();
          })
          .then((data) => {
            if (!data.success || !data.items || data.items.length === 0) {
              container.innerHTML = `
                <div class="alert alert-warning py-3">
                  <i class="bi bi-exclamation-triangle-fill me-2"></i>
                  Não há Ficha Técnica (BOM) cadastrada para este produto.
                </div>
              `;
              return;
            }

            let linhas = "";
            let temRuptura = false;

            data.items.forEach((item) => {
              const nec = parseFloat(item.qtd_por_barra) * opQtd;
              const estoque = parseFloat(item.estoque_atual);
              const saldo = estoque - nec;
              const falta = saldo < 0;

              if (falta) temRuptura = true;

              linhas += `
                <tr class="${falta ? "table-danger" : ""}">
                  <td><strong>${item.nome}</strong></td>
                  <td class="font-monospace">${parseFloat(item.qtd_por_barra).toFixed(4)} ${item.unidade}</td>
                  <td class="font-monospace fw-bold">${nec.toFixed(4)} ${item.unidade}</td>
                  <td class="font-monospace">${estoque.toFixed(4)} ${item.unidade}</td>
                  <td class="font-monospace fw-bold ${falta ? "text-danger" : "text-success"}">
                    ${saldo.toFixed(4)} ${item.unidade}
                  </td>
                  <td>
                    ${
                      falta
                        ? '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Ruptura</span>'
                        : '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Suficiente</span>'
                    }
                  </td>
                </tr>
              `;
            });

            container.innerHTML = `
              <div class="alert ${temRuptura ? "alert-danger" : "alert-success"} py-2 small mb-3">
                ${
                  temRuptura
                    ? '<i class="bi bi-exclamation-octagon-fill me-1"></i> <strong>Risco de Parada:</strong> Um ou mais insumos não atendem à quantidade planejada.'
                    : '<i class="bi bi-check-circle-fill me-1"></i> <strong>Tudo certo:</strong> Há matérias-primas suficientes para rodar este lote.'
                }
              </div>
              <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                  <thead class="table-light small text-uppercase">
                    <tr>
                      <th>Insumo</th>
                      <th>Consumo/Barra</th>
                      <th>Demanda Total</th>
                      <th>Estoque Atual</th>
                      <th>Saldo Projetado</th>
                      <th>Status PCP</th>
                    </tr>
                  </thead>
                  <tbody>${linhas}</tbody>
                </table>
              </div>
            `;
          })
          .catch(() => {
            container.innerHTML = `
              <div class="alert alert-danger py-2 small">
                Erro ao calcular a explosão de materiais. Verifique a conexão com o servidor.
              </div>
            `;
          });
      });
    });
  }
});
