export const TFT_COMMAND = {
  name: "tft",
  type: 1,
  description: "Consulta rápida de meta e jogadores de Teamfight Tactics",
  options: [
    { type: 1, name: "meta", description: "Mostra as principais composições do meta atual" },
    {
      type: 1,
      name: "comp",
      description: "Consulta uma composição do meta",
      options: [{ type: 3, name: "nome", description: "Nome ou parte do nome da composição", required: true }]
    },
    {
      type: 1,
      name: "champ",
      description: "Mostra comps e itens de um campeão",
      options: [{ type: 3, name: "nome", description: "Nome do campeão, ex.: Aphelios", required: true }]
    },
    {
      type: 1,
      name: "item",
      description: "Consulta estatísticas de um item",
      options: [{ type: 3, name: "nome", description: "Nome do item, ex.: Gume do Infinito", required: true }]
    },
    {
      type: 1,
      name: "player",
      description: "Consulta o TFT de um jogador monitorado",
      options: [{ type: 3, name: "nome", description: "Riot ID ou parte do nome, ex.: flafu", required: true }]
    },
    { type: 1, name: "players", description: "Mostra todos os jogadores monitorados com dados de TFT" }
  ]
};