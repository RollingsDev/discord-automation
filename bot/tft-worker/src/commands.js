export const LOL_COMMAND = {
  name: "lol",
  type: 1,
  description: "Consulta informações de League of Legends pela Riot API",
  options: [
    {
      type: 1,
      name: "player",
      description: "Rank, partidas recentes e maestrias de um Riot ID",
      options: [riotIdOption()]
    },
    {
      type: 1,
      name: "partida",
      description: "Mostra a partida ranqueada mais recente do jogador",
      options: [riotIdOption()]
    },
    {
      type: 1,
      name: "maestria",
      description: "Mostra as maiores maestrias do jogador",
      options: [riotIdOption()]
    }
  ]
};

export const TFT_COMMAND = {
  name: "tft",
  type: 1,
  description: "Consulta TFT pela Riot API e o snapshot de meta",
  options: [
    {
      type: 1,
      name: "player",
      description: "Rank e partidas recentes de qualquer Riot ID",
      options: [riotIdOption()]
    },
    {
      type: 1,
      name: "partida",
      description: "Mostra a partida de TFT mais recente do jogador",
      options: [riotIdOption()]
    },
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
    { type: 1, name: "players", description: "Mostra os jogadores monitorados com dados de TFT" }
  ]
};

export const COMMANDS = [LOL_COMMAND, TFT_COMMAND];

function riotIdOption() {
  return {
    type: 3,
    name: "riot_id",
    description: "Riot ID completo, ex.: crow#GT1",
    required: true
  };
}
