<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>🏆 Leaderboard ao Vivo — Desafio Flora Sabão</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #132419; color: #fff; font-family: sans-serif; }
        .podium-1 { background: linear-gradient(135deg, #ffd700 0%, #ffae00 100%); color: #000; }
        .podium-2 { background: linear-gradient(135deg, #c0c0c0 0%, #8c8c8c 100%); color: #000; }
        .podium-3 { background: linear-gradient(135deg, #cd7f32 0%, #9b5a1a 100%); color: #fff; }
    </style>
</head>
<body class="p-4">
    <div class="container text-center mb-4">
        <h1 class="display-5 fw-bold text-warning"><i class="bi bi-trophy-fill me-2"></i>Classificação Geral PCP</h1>
        <p class="text-white-50">Competição de Gestão de Fábrica & Sequenciamento Ótimo</p>
    </div>

    <div class="container">
        <div id="gridRanking" class="row g-3">
            <!-- Atualizado dinamicamente via polling a cada 5 segundos -->
        </div>
    </div>

    <script>
        function atualizarLeaderboard() {
            fetch('../api/jogo_api.php?action=leaderboard')
                .then(res => res.json())
                .then(data => {
                    const grid = document.getElementById('gridRanking');
                    grid.innerHTML = '';
                    data.ranking.forEach((eq, idx) => {
                        let classePodio = 'bg-dark text-white border border-secondary';
                        let medalha = `${idx + 1}º Lugar`;
                        if (idx === 0) { classePodio = 'podium-1 shadow-lg'; medalha = '🥇 Campeão Atual'; }
                        else if (idx === 1) { classePodio = 'podium-2'; medalha = '🥈 2º Lugar'; }
                        else if (idx === 2) { classePodio = 'podium-3'; medalha = '🥉 3º Lugar'; }

                        grid.innerHTML += `
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="card p-3 ${classePodio} rounded-4 h-100">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge bg-black text-white px-2 py-1">${medalha}</span>
                                        <h2 class="fw-bold my-0">${eq.score_total} <small class="fs-6">pts</small></h2>
                                    </div>
                                    <h4 class="fw-bold text-truncate">${eq.nome}</h4>
                                    <div class="small opacity-75">Caixa: R$ ${parseFloat(eq.caixa_disponivel).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                                </div>
                            </div>
                        `;
                    });
                });
        }
        setInterval(atualizarLeaderboard, 4000);
        atualizarLeaderboard();
    </script>
</body>
</html>