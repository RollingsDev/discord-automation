const token = process.env.DISCORD_BOT_TOKEN?.trim();
const appId = process.env.DISCORD_APP_ID?.trim();
const guildId = process.env.DISCORD_GUILD_ID?.trim();

if (!token || !appId || !guildId) {
  throw new Error(
    "Configure DISCORD_BOT_TOKEN, DISCORD_APP_ID e DISCORD_GUILD_ID."
  );
}

const command = {
  name: "receita",
  type: 1,
  description: "Consultas de CNPJ para o auditor",
  options: [
    {
      type: 1,
      name: "lote",
      description: "Processa o lote fixo ou uma planilha enviada e gera ISC + QSA",
      options: [
        {
          type: 11,
          name: "arquivo",
          description: "Planilha XLSX com Entidade e CNPJ",
          required: false
        }
      ]
    },
    {
      type: 1,
      name: "status",
      description: "Mostra o andamento do lote atual"
    }
  ]
};

const url =
  `https://discord.com/api/v10/applications/${appId}/guilds/${guildId}/commands`;

const response = await fetch(url, {
  method: "POST",
  headers: {
    authorization: `Bot ${token}`,
    "content-type": "application/json"
  },
  body: JSON.stringify(command)
});

const body = await response.text();

if (!response.ok) {
  throw new Error(
    `Discord respondeu HTTP ${response.status}: ${body}`
  );
}

const created = JSON.parse(body);

console.log(
  `/${created.name} registrado no servidor. ID: ${created.id}`
);
