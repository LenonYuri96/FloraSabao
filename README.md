# 🌿 Flora Sabão — Simulador Didático de PCP

Simulador web desenvolvido para aulas práticas de **Planejamento e Controle da Produção (PCP)**, **Engenharia de Métodos** e **Gestão de Operações**, baseado no contexto de uma microempresa de cosméticos artesanais sustentáveis.

---

## 🎯 Objetivos de Aprendizagem

- **Explosão de Materiais (MRP I / BOM):** Cálculo de necessidades líquidas lote a lote a partir de fórmulas unitárias.
- **Risco de Contaminação Cruzada & Matriz de Setup:** Identificação de gargalos na troca de fragrâncias e sequenciamento ótimo (_Aroma Suave &rarr; Aroma Intenso_).
- **Apontamento de Chão de Fábrica:** Registro de barras boas vs. refugo com baixa automática proporcional de matérias-primas no estoque.
- **Indicadores de Desempenho (KPIs):** Apuração prática de **OTIF**, **Aderência ao Plano**, **Taxa de Refugo** e **Ocupação da Capacidade**.

---

## 🚀 Como Executar em 3 Passos

### 1. Pré-requisitos

- **PHP 8.0+** com extensão PDO MySQL ativada.
- **MySQL 5.7+** ou **MariaDB 10.4+**.
- Servidor local como **XAMPP**, **Laragon**, **WampServer** ou container Docker.

### 2. Configuração do Banco de Dados

1. No phpMyAdmin ou terminal MySQL, crie o banco e importe os dois scripts SQL localizados na pasta `sql/`:
   ```sql
   source sql/flora_sabao.sql;
   source sql/usuarios.sql;
   ```
