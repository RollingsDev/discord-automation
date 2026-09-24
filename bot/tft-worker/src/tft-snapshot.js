const COLORS = { purple: 0x7c4dff, blue: 0x3498db, green: 0x2ecc71, gold: 0xf1c40f, red: 0xe74c3c };

export async function handleTftSnapshot(subcommand, env) {
  const name = subcommand?.name ?? "meta";
  const input = optionValue(subcommand, "nome");

  if (name === "players") {
    const riot = await fetchJson(env.RIOT_ANALYTICS_URL);
    return renderPlayers(riot);
  }

  const meta = await fetchJson(env.TFT_META_URL);

  if (name === "comp") return renderComp(meta, input);
  if (name === "champ") return renderChampion(meta, input);
  if (name === "item") return renderItem(meta, input);
  return renderMeta(meta);
}

function renderMeta(meta) {
  const comps = Array.isArray(meta.comps) ? meta.comps.slice(0, 5) : [];
  return embedResponse({
    title: "♟️ TFT Meta — principais comps",
    url: meta.source_url,
    description: metaHeader(meta),
    color: COLORS.purple,
    fields: comps.map((comp, index) => ({
      name: `${index + 1}. ${comp.name ?? "Comp"}`,
      value: [
        `**Win:** ${pct(comp.win_rate)} • **Top 4:** ${pct(comp.top4_rate)}`,
        `**Média:** ${num(comp.avg_place, 2)} • **Play:** ${num(comp.play_rate, 2)}`,
        topItemizedUnits(comp)
      ].join("\n"),
      inline: false
    })),
    footer: "Fonte do meta: tactics.tools • snapshot atualizado automaticamente"
  });
}

function renderComp(meta, input) {
  const comp = findBest(Array.isArray(meta.comps) ? meta.comps : [], input, row => row.name);
  if (!comp) return errorResponse(`Não encontrei uma comp parecida com **${safe(input)}** no snapshot atual.`);

  const units = Array.isArray(comp.units) ? comp.units : [];
  const itemLines = Object.entries(comp.unit_items ?? {})
    .filter(([, items]) => Array.isArray(items) && items.length)
    .slice(0, 8)
    .map(([unit, items]) => `**${unit}** → ${items.slice(0, 3).join(" / ")}`);

  return embedResponse({
    title: `🧩 ${comp.name}`,
    url: meta.source_url,
    description: metaHeader(meta),
    color: COLORS.purple,
    fields: [
      {
        name: "📊 Estatísticas",
        value: `Win **${pct(comp.win_rate)}** • Top 4 **${pct(comp.top4_rate)}**\nMédia **${num(comp.avg_place, 2)}** • Play **${num(comp.play_rate, 2)}**`,
        inline: false
      },
      { name: "👥 Unidades", value: units.length ? units.join(" • ") : "Sem unidades no snapshot.", inline: false },
      { name: "🧰 Itens por campeão", value: itemLines.length ? itemLines.join("\n") : "Sem itens associados.", inline: false }
    ]
  });
}

function renderChampion(meta, input) {
  const comps = Array.isArray(meta.comps) ? meta.comps : [];
  const championNames = [...new Set(comps.flatMap(comp => Array.isArray(comp.units) ? comp.units : []))];
  const champion = findBest(championNames.map(name => ({ name })), input, row => row.name)?.name;

  if (!champion) return errorResponse(`Não encontrei o campeão **${safe(input)}** no snapshot atual.`);

  const matching = comps.filter(comp => (comp.units ?? []).some(unit => normalize(unit) === normalize(champion)));
  return embedResponse({
    title: `🧙 ${champion} — TFT Meta`,
    url: meta.source_url,
    description: metaHeader(meta),
    color: COLORS.blue,
    fields: matching.slice(0, 5).map(comp => ({
      name: `🧩 ${comp.name}`,
      value: [
        `Win **${pct(comp.win_rate)}** • Top 4 **${pct(comp.top4_rate)}** • média **${num(comp.avg_place, 2)}**`,
        (comp.unit_items?.[champion] ?? []).length
          ? `Itens: ${comp.unit_items[champion].slice(0, 3).join(" / ")}`
          : "Sem itens específicos nessa variação."
      ].join("\n"),
      inline: false
    }))
  });
}

function renderItem(meta, input) {
  const item = findBest(Array.isArray(meta.items) ? meta.items : [], input, row => row.name);
  if (!item) return errorResponse(`Não encontrei o item **${safe(input)}** no snapshot atual.`);

  return embedResponse({
    title: `🧰 ${item.name}`,
    url: meta.items_url ?? meta.source_url,
    description: metaHeader(meta),
    color: COLORS.gold,
    fields: [
      {
        name: "📊 Estatísticas",
        value: `Win **${pct(item.win_rate)}** • Top 4 **${pct(item.top4_rate)}**\nMédia **${num(item.avg_place, 2)}** • Uso **${num(item.play_rate, 2)}/8**`,
        inline: false
      },
      {
        name: "👥 Principais usuários",
        value: (item.top_users ?? []).length ? item.top_users.join(" • ") : "Não disponível.",
        inline: false
      }
    ]
  });
}

function renderPlayers(riot) {
  const players = Object.entries(riot.players ?? {})
    .map(([id, player]) => ({ id, ...player }))
    .filter(player => player.tft_rank || (player.tft_matches ?? []).length);

  if (!players.length) return errorResponse("Ainda não há jogadores monitorados com dados de TFT.");

  players.sort((a, b) => rankScore(b.tft_rank) - rankScore(a.tft_rank));

  return embedResponse({
    title: "👥 TFT da galera",
    color: COLORS.green,
    description: players.map(player => {
      const recent = tftRecent(player.tft_matches ?? []);
      const lines = [`**${riotId(player)}** — ${rankLabel(player.tft_rank)}`];
      if (recent.games) lines.push(`Top4 ${pct(recent.top4Rate)} • 1º ${pct(recent.firstRate)} • média ${num(recent.avgPlacement, 2)}`);
      return lines.join("\n");
    }).join("\n\n"),
    footer: "Snapshot do Riot Analytics"
  });
}

function tftRecent(matches) {
  const placements = (Array.isArray(matches) ? matches.slice(0, 20) : [])
    .map(row => Number(row.placement))
    .filter(value => Number.isFinite(value) && value >= 1 && value <= 8);

  return {
    games: placements.length,
    top4Rate: placements.length ? placements.filter(value => value <= 4).length / placements.length : 0,
    firstRate: placements.length ? placements.filter(value => value === 1).length / placements.length : 0,
    avgPlacement: placements.length ? placements.reduce((sum, value) => sum + value, 0) / placements.length : 0
  };
}

function rankLabel(rank) {
  if (!rank) return "Unranked";
  return `${titleCase(rank.tier ?? "")} ${rank.rank ?? ""} • ${Number(rank.lp ?? rank.leaguePoints ?? 0)} LP`.trim();
}

function rankScore(rank) {
  if (!rank) return -1;
  const tiers = { IRON: 0, BRONZE: 1, SILVER: 2, GOLD: 3, PLATINUM: 4, EMERALD: 5, DIAMOND: 6, MASTER: 7, GRANDMASTER: 8, CHALLENGER: 9 };
  const divisions = { IV: 0, III: 1, II: 2, I: 3 };
  return (tiers[String(rank.tier ?? "").toUpperCase()] ?? -1) * 10000 + (divisions[String(rank.rank ?? "").toUpperCase()] ?? 0) * 1000 + Number(rank.lp ?? rank.leaguePoints ?? 0);
}

function riotId(player) {
  const game = player.riot_id?.game_name ?? player.id ?? "Jogador";
  const tag = player.riot_id?.tag_line ?? "";
  return tag ? `${game}#${tag}` : game;
}

function topItemizedUnits(comp) {
  const lines = Object.entries(comp.unit_items ?? {})
    .filter(([, items]) => Array.isArray(items) && items.length)
    .slice(0, 2)
    .map(([unit, items]) => `**${unit}** → ${items.slice(0, 3).join(" / ")}`);
  return lines.length ? lines.join("\n") : "Sem itens destacados.";
}

function metaHeader(meta) {
  const patch = meta.patch ? `Patch **${meta.patch}**` : "Patch atual";
  const rank = meta.rank_filter ? ` • **${meta.rank_filter}**` : "";
  return patch + rank;
}

function findBest(rows, input, label) {
  const query = normalize(input);
  if (!query) return null;

  return rows.map(row => {
    const value = normalize(label(row));
    let score = 0;
    if (value === query) score = 100;
    else if (value.startsWith(query)) score = 80;
    else if (value.includes(query)) score = 60;
    return { row, score };
  }).filter(result => result.score > 0).sort((a, b) => b.score - a.score)[0]?.row ?? null;
}

function optionValue(subcommand, name) {
  return subcommand?.options?.find(option => option.name === name)?.value?.toString() ?? "";
}

function normalize(value) {
  return String(value ?? "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]/g, "");
}

function pct(value) { return `${(Number(value ?? 0) * 100).toFixed(1).replace(".", ",")}%`; }
function num(value, decimals = 2) { return Number(value ?? 0).toFixed(decimals).replace(".", ","); }
function safe(value) { return String(value ?? "").replace(/[*_~|`]/g, ""); }
function titleCase(value) { return String(value ?? "").toLowerCase().replace(/\b\w/g, char => char.toUpperCase()); }

function embedResponse({ title, url, description, color, fields = [], footer }) {
  const embed = {
    title: trim(title, 256),
    color: color ?? COLORS.purple,
    fields: fields.slice(0, 25).map(field => ({ ...field, name: trim(field.name, 256), value: trim(field.value, 1024) })),
    timestamp: new Date().toISOString()
  };
  if (url) embed.url = url;
  if (description) embed.description = trim(description, 4096);
  if (footer) embed.footer = { text: trim(footer, 2048) };
  return { embeds: [embed], allowed_mentions: { parse: [] } };
}

function errorResponse(message) {
  return { embeds: [{ title: "🔎 Não encontrei", description: trim(message, 4096), color: COLORS.red }], allowed_mentions: { parse: [] } };
}

async function fetchJson(url) {
  if (!url) throw new Error("URL de dados não configurada.");
  const response = await fetch(url, { cf: { cacheEverything: true, cacheTtl: 60 } });
  if (!response.ok) throw new Error(`Fonte retornou HTTP ${response.status}`);
  return response.json();
}

function trim(value, max) {
  const string = String(value ?? "");
  return string.length <= max ? string : string.slice(0, max - 1) + "…";
}
