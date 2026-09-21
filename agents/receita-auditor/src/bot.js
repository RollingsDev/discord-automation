import "dotenv/config";
import fs from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import {
  AttachmentBuilder,
  Client,
  Events,
  GatewayIntentBits
} from "discord.js";
import { processWorkbook } from "./processor.js";
import { formatCnpj } from "./utils.js";

const required = [
  "DISCORD_BOT_TOKEN",
  "DISCORD_GUILD_ID",
  "DISCORD_AUDITOR_CHANNEL_ID",
  "DISCORD_AUDITOR_USER_ID"
];

for (const key of required) {
  if (!String(process.env[key] ?? "").trim()) {
    throw new Error(`${key} não configurado.`);
  }
}

const client = new Client({
  intents: [GatewayIntentBits.Guilds]
});

let activeJob = null;

client.once(Events.ClientReady, readyClient => {
  console.log(`Auditor Receita conectado como ${readyClient.user.tag}.`);
});

client.on(Events.InteractionCreate, async interaction => {
  if (!interaction.isChatInputCommand()) return;
  if (interaction.commandName !== "receita") return;

  if (!isAuthorized(interaction)) {
    await interaction.reply({
      content:
        "Este comando está disponível somente no canal privado e para o auditor autorizado.",
      ephemeral: true
    });
    return;
  }

  const subcommand = interaction.options.getSubcommand();

  if (subcommand === "status") {
    await interaction.reply({
      content: statusText(),
      ephemeral: true
    });
    return;
  }

  if (subcommand !== "lote") {
    await interaction.reply({
      content: "Subcomando não reconhecido.",
      ephemeral: true
    });
    return;
  }

  if (activeJob) {
    await interaction.reply({
      content:
        `Já existe um lote em andamento: **${activeJob.current}/${activeJob.total || "?"}**.`,
      ephemeral: true
    });
    return;
  }

  const attachment = interaction.options.getAttachment("arquivo", true);

  if (!/\.xlsx?$/i.test(attachment.name ?? "")) {
    await interaction.reply({
      content: "Envie uma planilha `.xlsx` ou `.xls`.",
      ephemeral: true
    });
    return;
  }

  const jobId = new Date()
    .toISOString()
    .replace(/[-:TZ.]/g, "")
    .slice(0, 14);

  const jobRoot = path.resolve(
    process.cwd(),
    "data",
    "jobs",
    jobId
  );
  const inputDir = path.join(jobRoot, "input");
  const outputDir = path.join(jobRoot, "output");
  const inputPath = path.join(
    inputDir,
    sanitizeInputName(attachment.name || "estrutura.xlsx")
  );

  await fs.mkdir(inputDir, { recursive: true });

  const response = await fetch(attachment.url);

  if (!response.ok) {
    await interaction.reply({
      content: `Não consegui baixar a planilha do Discord. HTTP ${response.status}.`,
      ephemeral: true
    });
    return;
  }

  await fs.writeFile(
    inputPath,
    Buffer.from(await response.arrayBuffer())
  );

  activeJob = {
    id: jobId,
    current: 0,
    total: 0,
    company: "",
    status: "PREPARANDO",
    startedAt: new Date().toISOString()
  };

  await interaction.reply({
    content:
      "📦 **Lote da Receita recebido.**\n" +
      "Vou abrir o navegador nesta máquina. Quando aparecer **Sou humano**, resolva manualmente; o agente continua sozinho depois disso.\n" +
      `Job: \`${jobId}\``
  });

  void runJob({
    interaction,
    inputPath,
    outputDir,
    jobRoot,
    jobId
  });
});

await client.login(process.env.DISCORD_BOT_TOKEN);

function isAuthorized(interaction) {
  return (
    interaction.guildId === process.env.DISCORD_GUILD_ID &&
    interaction.channelId === process.env.DISCORD_AUDITOR_CHANNEL_ID &&
    interaction.user.id === process.env.DISCORD_AUDITOR_USER_ID
  );
}

function statusText() {
  if (!activeJob) {
    return "✅ Nenhum lote da Receita está em processamento.";
  }

  return [
    `⏳ Job \`${activeJob.id}\``,
    `Status: **${activeJob.status}**`,
    `Progresso: **${activeJob.current}/${activeJob.total || "?"}**`,
    activeJob.company ? `Empresa: ${activeJob.company}` : "",
    `Início: ${activeJob.startedAt}`
  ]
    .filter(Boolean)
    .join("\n");
}

async function runJob({
  interaction,
  inputPath,
  outputDir,
  jobRoot,
  jobId
}) {
  const channel = interaction.channel;
  const profileDir = path.resolve(
    process.env.RECEITA_BROWSER_PROFILE ||
      path.join(process.cwd(), "data", "browser-profile")
  );
  const limit =
    Number(process.env.RECEITA_LIMIT ?? 0) || 0;
  const maxMb =
    Number(process.env.DISCORD_UPLOAD_MAX_MB ?? 9) || 9;

  try {
    const result = await processWorkbook({
      inputPath,
      outputRoot: outputDir,
      profileDir,
      limit,
      uploadMaxBytes: Math.floor(maxMb * 1024 * 1024),
      onEvent: async message => {
        console.log(message);
      },
      onProgress: async progress => {
        activeJob.current = progress.current;
        activeJob.total = progress.total;
        activeJob.company =
          `${formatCnpj(progress.company.cnpj)} — ${progress.company.name}`;
        activeJob.status = progress.status;
      },
      onHumanRequired: async company => {
        activeJob.status = "AGUARDANDO SOU HUMANO";
        activeJob.company =
          `${formatCnpj(company.cnpj)} — ${company.name}`;

        await channel.send({
          content:
            "🔐 **Ação humana necessária**\n" +
            `No navegador desta máquina, marque **Sou humano** para **${formatCnpj(company.cnpj)} — ${company.name}**.\n` +
            "Não precisa clicar em mais nada; depois da validação o agente continua sozinho."
        });
      }
    });

    const summary =
      "✅ **Lote da Receita concluído**\n" +
      `CNPJs: **${result.companies}**\n` +
      `Sucesso: **${result.ok}**\n` +
      `Erros: **${result.errors}**\n` +
      `Ignorados na planilha: **${result.skipped}**`;

    await channel.send({ content: summary });

    for (const filePath of result.discordFiles) {
      await channel.send({
        content:
          result.discordFiles.length === 1
            ? "📦 ZIP do lote:"
            : "📦 Parte do lote (o ZIP completo também ficou salvo no computador):",
        files: [
          new AttachmentBuilder(filePath, {
            name: path.basename(filePath)
          })
        ]
      });
    }

    if (result.discordFiles.length > 1) {
      await channel.send({
        content:
          "ℹ️ O ZIP completo ultrapassou o limite configurado para anexos do Discord, então o bot enviou partes menores. O arquivo completo está salvo localmente em:\n" +
          `\`\`\`\n${result.fullZip}\n\`\`\``
      });
    }
  } catch (error) {
    console.error(error);

    await channel
      .send({
        content:
          "❌ **Falha no lote da Receita**\n" +
          (error instanceof Error ? error.message : String(error)) +
          "\nOs arquivos já concluídos permanecem na pasta do job."
      })
      .catch(() => {});
  } finally {
    activeJob = null;

    console.log(
      `Job ${jobId} finalizado. Pasta local: ${jobRoot}`
    );
  }
}

function sanitizeInputName(value) {
  return String(value)
    .replace(/[<>:"/\\|?*\u0000-\u001F]/g, "_")
    .slice(0, 120);
}
