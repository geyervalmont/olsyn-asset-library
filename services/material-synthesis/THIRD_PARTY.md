# Vendored model component

`controlnetvae.py` is from the StableDelight authors' pinned model repository:
https://huggingface.co/Stable-X/yoso-delight-v0-4-base/blob/a06a968fbc59830ad36f2a7256d191bd0c20d3ce/controlnet/controlnetvae.py

Original SHA256: `12db63c03bc5bed7efd81c3356d790802061695b6e178eee3f57d56536f2719b`.
The original copyright notice is retained. The component is licensed under
[Apache License 2.0](LICENSE-APACHE-2.0.txt). Its ControlNetOutput import was
updated to the diffusers 0.35 package path; the inference implementation is unchanged.
This component is packaged explicitly so loading the cleanup checkpoint never
requires fetching or executing model-hub code at runtime.

RGB→X source and weights use the Adobe Research License, retained in the pinned
source checkout and model bundle. CHORD and StableDelight model/source notices
remain in their respective pinned checkouts and bundles. OPAL currently uses
these as an internal R&D proof of concept.
