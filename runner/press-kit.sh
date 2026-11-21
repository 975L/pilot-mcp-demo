#!/bin/bash
# Builds the press kit PDF of the card the runner hands over (press-kit.sh <workdir> [comment]), in its work folder, through the site's own console
set -euo pipefail

# The card this run is about, from its work folder card-<id>
Card=$(basename "$1")
Card=${Card#card-}

# The project root, where the console reads the demo's database
cd "$(dirname "$0")/.."
php bin/console app:press-kit "$Card" "$1" --no-ansi

# Comments are the AI's to deal with: each one handed here is left open, the PDF being rebuilt from the card as it stands
while IFS= read -r Line; do
    if [[ $Line =~ ^([0-9]+)\.\  ]]; then
        echo "FEEDBACK ${BASH_REMATCH[1]} The press kit only lays the card out: hand this comment to the AI step"
    fi
done <<< "${2:-}"
