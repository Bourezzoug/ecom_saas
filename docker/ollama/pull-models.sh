#!/bin/sh
# Pull every model referenced by AI_MODEL_* in apps/platform/.env. Safe to re-run.
set -e

echo "Waiting for Ollama at $OLLAMA_HOST ..."
until ollama list >/dev/null 2>&1; do sleep 2; done

# Keep .env order (planner, copy, vision) so text models are usable first; drop duplicates.
for model in $(printf '%s\n' "$AI_MODEL_PLANNER" "$AI_MODEL_COPY" "$AI_MODEL_VISION" | awk 'NF && !seen[$0]++'); do
    [ -z "$model" ] && continue
    echo "Pulling $model"
    ollama pull "$model"
done

echo "Models ready:"
ollama list
