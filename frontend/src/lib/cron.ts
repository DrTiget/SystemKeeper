const pad = (n: string) => n.padStart(2, '0')
const DAYS = ['воскресеньям', 'понедельникам', 'вторникам', 'средам', 'четвергам', 'пятницам', 'субботам']

/** Человекочитаемое описание cron из 5 полей; для нестандартных выражений — null. */
export function describeCron(expr: string | null): string | null {
  if (!expr) return null
  const p = expr.trim().split(/\s+/)
  if (p.length !== 5) return null
  const [min, hour, dom, mon, dow] = p
  const num = /^\d+$/
  const time = num.test(min) && num.test(hour) ? `${pad(hour)}:${pad(min)}` : null

  if (min === '*' && hour === '*' && dom === '*' && mon === '*' && dow === '*') return 'Каждую минуту'
  const stepMin = /^\*\/(\d+)$/.exec(min)
  if (stepMin && hour === '*' && dom === '*' && mon === '*' && dow === '*') return `Каждые ${stepMin[1]} мин`
  if (num.test(min) && hour === '*' && dom === '*' && mon === '*' && dow === '*') return `Каждый час в :${pad(min)}`
  const stepHour = /^\*\/(\d+)$/.exec(hour)
  if (num.test(min) && stepHour && dom === '*' && mon === '*' && dow === '*') return `Каждые ${stepHour[1]} ч в :${pad(min)}`
  if (!time) return null
  if (dom === '*' && mon === '*' && dow === '*') return `Каждый день в ${time}`
  if (dom === '*' && mon === '*' && num.test(dow)) return `По ${DAYS[Number(dow) % 7]} в ${time}`
  if (dom === '*' && mon === '*' && dow === '1-5') return `По будням в ${time}`
  if (num.test(dom) && mon === '*' && dow === '*') return `${dom}-го числа каждого месяца в ${time}`
  return null
}
